<?php

namespace SocialDept\TenantDomains\Actions;

use SocialDept\TenantDomains\Contracts\DnsResolver;
use SocialDept\TenantDomains\Core\Detection\ProxyDetector;
use SocialDept\TenantDomains\Core\DomainName;
use SocialDept\TenantDomains\Core\Platform;
use SocialDept\TenantDomains\Data\RoutingResult;
use SocialDept\TenantDomains\Exceptions\DnsUnavailable;

/**
 * Whether a domain's traffic actually points at us.
 *
 * Accepts three shapes, because tenants legitimately arrive at all three:
 *
 *  1. a literal CNAME to our target, which is what plain subdomains use
 *  2. an address intersecting our ingress set, which covers apex domains and
 *     any provider that flattens a CNAME into A records before anyone sees it
 *  3. nothing resolvable, because the records are proxied and hidden
 *
 * The ingress set is the configured addresses **unioned with whatever the CNAME
 * target resolves to right now**. Comparing against one configured IP reports a
 * platform's own passthrough edge as a misconfiguration, and goes stale the
 * moment the origin moves.
 */
class VerifyRouting
{
    public function __construct(
        private readonly DnsResolver $dns,
        private readonly Platform $platform,
        private readonly ProxyDetector $proxies,
    ) {
        //
    }

    /**
     * @throws DnsUnavailable
     */
    public function __invoke(DomainName|string $domain): RoutingResult
    {
        $domain = $domain instanceof DomainName ? $domain : DomainName::make($domain);
        $target = $this->platform->cnameTarget;

        foreach ($this->dns->records($domain->value, DNS_CNAME) as $record) {
            $found = strtolower(rtrim((string) ($record['target'] ?? ''), '.'));

            if ($found === strtolower($target)) {
                return RoutingResult::verified('cname', $found);
            }
        }

        $domainIps = $this->dns->ips($domain->value);
        $acceptable = $this->acceptableIps();

        if (array_intersect($domainIps, $acceptable) !== []) {
            return RoutingResult::verified('a_record', implode(', ', $domainIps));
        }

        // A proxy answers with its own addresses, so the record's target is
        // invisible. Unknown, not wrong, and only a real request can settle it.
        if ($this->proxies->anyIsCloudflare($domainIps)) {
            return RoutingResult::proxied(
                resolved: implode(', ', $domainIps),
                expectedCname: $target,
                expectedIps: $acceptable,
                hint: "This domain is proxied through Cloudflare, so we cannot read its records. We will confirm it by requesting the domain itself. If that does not work, set {$domain->value} to 'DNS only' (grey cloud) while it is being set up.",
            );
        }

        return RoutingResult::failed(
            resolved: $domainIps === [] ? null : implode(', ', $domainIps),
            expectedCname: $target,
            expectedIps: $acceptable,
        );
    }

    /**
     * Every address an A record may legitimately hold.
     *
     * @return array<int, string>
     */
    public function acceptableIps(): array
    {
        $live = [];

        try {
            $live = $this->dns->ips($this->platform->cnameTarget);
        } catch (DnsUnavailable) {
            // Our own target failing must not fail the tenant's check.
        }

        return array_values(array_unique(array_filter([
            ...$live,
            ...$this->platform->ingressIps,
        ])));
    }
}
