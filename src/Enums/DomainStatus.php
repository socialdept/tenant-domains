<?php

namespace SocialDept\TenantDomains\Enums;

enum DomainStatus: string
{
    /** Recorded, but nothing about its DNS has been proven. */
    case Pending = 'pending';

    /**
     * DNS is right, but nothing has yet proved a request reaches the origin.
     *
     * DNS resolution says where a name points, not what answers there. A proxied
     * record resolves to the proxy, and whatever the proxy does next is invisible
     * from outside. It may forward to us, redirect elsewhere, or serve a cached
     * page. A tenant with a leftover redirect rule passed every DNS check and
     * then could not use the domain at all.
     *
     * A domain in this state is routed and holds a certificate, because the
     * reachability check needs something to reach, but it is not yet in service.
     */
    case Confirming = 'confirming';

    /** Proven, routed, reachable, in service. */
    case Verified = 'verified';

    /** Checked and wrong. A retry moves it back to Pending. */
    case Failed = 'failed';

    /**
     * Whether the edge should route and hold a certificate for this domain.
     *
     * Wider than verified on purpose: confirming a domain means making a real
     * request to it, which cannot happen until it is served.
     */
    public function isRoutable(): bool
    {
        return $this === self::Verified || $this === self::Confirming;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Confirming => 'Finishing setup',
            self::Verified => 'Active',
            self::Failed => 'Needs attention',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
