<?php

namespace SocialDept\TenantDomains\Enums;

/**
 * How certificates for custom domains are obtained.
 *
 * Not a cosmetic setting. It decides what the platform can offer. Both source
 * apps run different modes, and the difference is why one supports wildcard
 * handle hosts and the other does not.
 */
enum CertificateMode: string
{
    /**
     * The edge asks us at handshake time and solves HTTP-01 / TLS-ALPN itself.
     *
     * No per-domain configuration, but the challenge travels over the public
     * request path: traffic must already reach the box, a CDN in front can break
     * it, and **wildcards are impossible** because neither challenge can prove
     * one.
     */
    case OnDemand = 'on_demand';

    /**
     * DNS-01, solved in our own zone via the tenant's `_acme-challenge` CNAME.
     *
     * Costs a per-domain policy on the edge and a zone we can write to, and buys
     * wildcards plus independence from whatever sits in front of the tenant's
     * domain.
     */
    case DelegatedDns = 'delegated_dns';

    public function supportsWildcards(): bool
    {
        return $this === self::DelegatedDns;
    }

    /**
     * Whether a tenant must create an `_acme-challenge` CNAME for this mode.
     * Asking for a record nothing reads is worse than not asking.
     */
    public function needsDelegationRecord(): bool
    {
        return $this === self::DelegatedDns;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
