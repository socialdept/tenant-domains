<?php

namespace SocialDept\TenantDomains\Dns;

use SocialDept\TenantDomains\Contracts\DnsResolver;
use SocialDept\TenantDomains\Exceptions\DnsUnavailable;

/**
 * The platform resolver, via `dns_get_record()` and `gethostbynamel()`.
 *
 * Reserved TLDs (RFC 2606 / 6761) are short-circuited so local `.test` domains
 * do not pay a resolver round-trip on every page render.
 */
class SystemResolver implements DnsResolver
{
    /**
     * @var array<int, string>
     */
    private const NON_RESOLVABLE_TLDS = ['test', 'localhost', 'local', 'invalid', 'example'];

    public function __construct(private readonly string $canary = '1.1.1.1')
    {
    }

    public function records(string $host, int $type): array
    {
        if ($this->isUnresolvable($host)) {
            return [];
        }

        $records = @dns_get_record($host, $type);

        if ($records === false) {
            $this->assertResolverReachable($host);

            return [];
        }

        return $records;
    }

    public function ips(string $host): array
    {
        if ($this->isUnresolvable($host)) {
            return [];
        }

        $ips = gethostbynamel($host);

        if ($ips === false) {
            $this->assertResolverReachable($host);

            return [];
        }

        return $ips;
    }

    /**
     * Decide whether a failed lookup was about the host or about us.
     *
     * `dns_get_record()` and `gethostbynamel()` both return `false` for a
     * resolver failure *and*, on some platforms, for a name that simply does not
     * exist, so the return value alone cannot tell the two apart. Asking for a
     * name that must resolve settles it: if the canary answers, the resolver is
     * fine and the original failure was about the host, which is the ordinary case
     * during setup. If the canary fails too, the resolver is the problem.
     *
     * Deliberately fails **open**: any doubt is treated as the host's absence,
     * because wrongly reporting an outage on every unconfigured domain would be
     * worse than the bug this replaces.
     *
     * @throws DnsUnavailable
     */
    protected function assertResolverReachable(string $host): void
    {
        if (@gethostbynamel($this->canary) === false) {
            throw DnsUnavailable::forHost($host);
        }
    }

    /**
     * Whether the host sits under a reserved TLD that can never carry real NS
     * records, so a lookup against it can only fail.
     */
    private function isUnresolvable(string $host): bool
    {
        $tld = strtolower(substr(strrchr(trim($host, '.'), '.') ?: '', 1));

        return in_array($tld, self::NON_RESOLVABLE_TLDS, true);
    }
}
