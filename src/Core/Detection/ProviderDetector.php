<?php

namespace SocialDept\TenantDomains\Core\Detection;

use SocialDept\TenantDomains\Contracts\DnsResolver;
use SocialDept\TenantDomains\Core\DomainName;
use SocialDept\TenantDomains\Enums\RoutingMode;
use SocialDept\TenantDomains\Exceptions\DnsUnavailable;

/**
 * Which DNS provider a domain is delegated to, from its nameservers.
 *
 * Answers "this domain is hosted at Cloudflare", which stays true for as long as
 * the delegation stands, whatever the records themselves do. Right for choosing
 * which setup instructions to show, and **wrong for anything about proxy
 * behaviour**, which is {@see ProxyDetector}.
 */
class ProviderDetector
{
    private const CLOUDFLARE = 'Cloudflare';

    /**
     * Nameserver-suffix → provider, with whether that provider can point an
     * apex/root domain at an external CNAME target (via flattening / ALIAS /
     * ANAME). Drives which routing record the instructions recommend. A hint
     * only, and the tenant can always pick the other one. Not exhaustive.
     *
     * @var array<string, array{name: string, supports_apex_cname: bool}>
     */
    public const PROVIDERS = [
        // Flattening / ALIAS / ANAME, so a CNAME works at the root.
        'cloudflare.com' => ['name' => self::CLOUDFLARE, 'supports_apex_cname' => true],
        'registrar-servers.com' => ['name' => 'Namecheap', 'supports_apex_cname' => true],
        'spaceship.net' => ['name' => 'Spaceship', 'supports_apex_cname' => true],
        'porkbun.com' => ['name' => 'Porkbun', 'supports_apex_cname' => true],
        'dnsowl.com' => ['name' => 'NameSilo', 'supports_apex_cname' => true],
        'gandi.net' => ['name' => 'Gandi', 'supports_apex_cname' => true],
        'name.com' => ['name' => 'Name.com', 'supports_apex_cname' => true],
        'dyna-ns.net' => ['name' => 'Dynadot', 'supports_apex_cname' => true],
        'dnsimple.com' => ['name' => 'DNSimple', 'supports_apex_cname' => true],
        'dnsmadeeasy.com' => ['name' => 'DNS Made Easy', 'supports_apex_cname' => true],
        'easydns.' => ['name' => 'easyDNS', 'supports_apex_cname' => true],
        'cloudns.net' => ['name' => 'ClouDNS', 'supports_apex_cname' => true],
        'nsone.net' => ['name' => 'NS1', 'supports_apex_cname' => true],

        // No apex CNAME, or ALIAS only to the provider's own resources.
        'wixdns.net' => ['name' => 'Wix', 'supports_apex_cname' => false],
        'domaincontrol.com' => ['name' => 'GoDaddy', 'supports_apex_cname' => false],
        'squarespacedns.com' => ['name' => 'Squarespace', 'supports_apex_cname' => false],
        'googledomains.com' => ['name' => 'Google Domains', 'supports_apex_cname' => false],
        'ui-dns.' => ['name' => 'IONOS', 'supports_apex_cname' => false],
        'ovh.net' => ['name' => 'OVH', 'supports_apex_cname' => false],
        'dns-parking.com' => ['name' => 'Hostinger', 'supports_apex_cname' => false],
        'hover.com' => ['name' => 'Hover', 'supports_apex_cname' => false],
        'awsdns' => ['name' => 'Amazon Route 53', 'supports_apex_cname' => false],
        'azure-dns' => ['name' => 'Azure DNS', 'supports_apex_cname' => false],
        'digitalocean.com' => ['name' => 'DigitalOcean', 'supports_apex_cname' => false],
        'inwx.de' => ['name' => 'INWX', 'supports_apex_cname' => false],
        'inwx.eu' => ['name' => 'INWX', 'supports_apex_cname' => false],
        'inwx.com' => ['name' => 'INWX', 'supports_apex_cname' => false],
    ];

    public function __construct(private readonly DnsResolver $dns)
    {
    }

    /**
     * Identify the provider behind a domain.
     *
     * An unrecognised provider yields a null `supports_apex_cname` rather than
     * false: we genuinely do not know, and the caller treats unknown-at-the-apex
     * as "recommend the A record", which works everywhere.
     *
     * @return array{provider: string|null, is_cloudflare: bool, supports_apex_cname: bool|null}
     */
    public function detect(DomainName|string $domain): array
    {
        $provider = $this->match($this->nameservers($domain));

        if ($provider === null) {
            return ['provider' => null, 'is_cloudflare' => false, 'supports_apex_cname' => null];
        }

        return [
            'provider' => $provider['name'],
            'is_cloudflare' => $provider['name'] === self::CLOUDFLARE,
            'supports_apex_cname' => $provider['supports_apex_cname'],
        ];
    }

    /**
     * Recommend how a domain's root record should point at us.
     *
     * Subdomains always take a CNAME, which works everywhere. At the apex a
     * CNAME only works where the provider offers flattening / ALIAS, so an
     * unrecognised provider is steered to the A record: that resolves
     * everywhere, making the CNAME the special case rather than the default.
     * A recommendation only, and the tenant can always switch modes.
     */
    public function recommendRoutingMode(DomainName|string $domain): RoutingMode
    {
        $domain = $domain instanceof DomainName ? $domain : DomainName::make($domain);

        if (! $domain->isApex()) {
            return RoutingMode::Cname;
        }

        $provider = $this->match($this->nameservers($domain));

        if ($provider === null) {
            return RoutingMode::ARecord;
        }

        return $provider['supports_apex_cname'] ? RoutingMode::Cname : RoutingMode::ARecord;
    }

    /**
     * The domain's delegated nameservers plus the provider they belong to.
     *
     * Keeps the raw records, because the common cause of "records added but
     * nothing resolves" is editing DNS at the registrar while the domain is
     * delegated elsewhere, and only the raw list shows that.
     *
     * @return array{records: array<int, string>, provider: string|null}
     */
    public function nameserverReport(DomainName|string $domain): array
    {
        $records = $this->nameservers($domain);

        return ['records' => $records, 'provider' => $this->match($records)['name'] ?? null];
    }

    /**
     * Nameservers are looked up on the **registrable domain**, not the host: a
     * subdomain rarely has its own NS records, and asking for them returns
     * nothing rather than the zone's real delegation.
     *
     * @return array<int, string>
     *
     * @throws DnsUnavailable
     */
    public function nameservers(DomainName|string $domain): array
    {
        $domain = $domain instanceof DomainName ? $domain : DomainName::make($domain);
        $zone = $domain->registrableDomain() ?? $domain->value;

        $names = [];

        foreach ($this->dns->records($zone, DNS_NS) as $record) {
            $target = strtolower((string) ($record['target'] ?? ''));

            if ($target !== '') {
                $names[] = $target;
            }
        }

        return $names;
    }

    /**
     * Match the provider whose nameserver suffix appears in the given hostnames.
     *
     * Uses `str_contains` rather than `str_ends_with` because several keys are
     * infixes, e.g. `azure-dns` in `ns1-01.azure-dns.com`.
     *
     * @param  array<int, string>  $nameservers
     * @return array{name: string, supports_apex_cname: bool}|null
     */
    public function match(array $nameservers): ?array
    {
        foreach (self::PROVIDERS as $suffix => $provider) {
            foreach ($nameservers as $nameserver) {
                if (str_contains($nameserver, $suffix)) {
                    return $provider;
                }
            }
        }

        return null;
    }
}
