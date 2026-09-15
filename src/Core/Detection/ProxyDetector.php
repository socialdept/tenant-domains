<?php

namespace SocialDept\TenantDomains\Core\Detection;

use SocialDept\TenantDomains\Contracts\DnsResolver;
use SocialDept\TenantDomains\Core\DomainName;

/**
 * Whether a host's records are proxied through Cloudflare **right now**.
 *
 * Deliberately separate from {@see ProviderDetector}, which matches delegated
 * nameservers and so answers "this domain is hosted at Cloudflare", which stays
 * true forever, whatever the records do. Grey-cloud every record and that stays true,
 * which is how one source app came to tell a tenant their traffic was proxied
 * eight days after they turned the proxy off.
 *
 * This resolves the host and checks whether the answer is a Cloudflare edge
 * address. A proxied record answers with one, and a direct record answers with the
 * origin. It is the only one of the two that changes when a tenant flips the
 * cloud, and therefore the only one anything about proxy *behaviour* may use.
 */
class ProxyDetector
{
    /**
     * Cloudflare's published IPv4 proxy ranges.
     *
     * Covering only the two largest blocks left tenants on the smaller ones
     * looking proxied to nobody: their records resolved to an address we did not
     * recognise, so the setup flow told them to add a record they already had.
     *
     * @see https://www.cloudflare.com/ips-v4
     *
     * @var array<int, string>
     */
    public const CLOUDFLARE_RANGES = [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
    ];

    public function __construct(private readonly DnsResolver $dns)
    {
    }

    /**
     * Whether the host currently answers from a Cloudflare edge address.
     */
    public function isProxied(DomainName|string $host): bool
    {
        $host = $host instanceof DomainName ? $host->value : DomainName::normalise($host);

        return $this->anyIsCloudflare($this->dns->ips($host));
    }

    /**
     * Whether any of the given addresses sits behind Cloudflare's proxy, so the
     * record's real target is hidden from us.
     *
     * @param  array<int, string>  $ips
     */
    public function anyIsCloudflare(array $ips): bool
    {
        foreach ($ips as $ip) {
            if ($this->isCloudflareIp($ip)) {
                return true;
            }
        }

        return false;
    }

    public function isCloudflareIp(string $ip): bool
    {
        $long = ip2long($ip);

        if ($long === false) {
            return false;
        }

        foreach (self::CLOUDFLARE_RANGES as $range) {
            [$subnet, $bits] = explode('/', $range);

            // No entry is a /0, which would shift by 32 and is undefined in PHP.
            $mask = -1 << (32 - (int) $bits);

            if (($long & $mask) === (ip2long($subnet) & $mask)) {
                return true;
            }
        }

        return false;
    }
}
