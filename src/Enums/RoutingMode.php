<?php

namespace SocialDept\TenantDomains\Enums;

/**
 * How a custom domain's root record points at us.
 *
 * Only the apex has a real choice: a CNAME at the root needs ALIAS / CNAME
 * flattening, which many registrars do not offer, so those point an A record at
 * an ingress address instead. Subdomains always take a CNAME.
 */
enum RoutingMode: string
{
    case Cname = 'cname';
    case ARecord = 'a_record';

    /**
     * The DNS record type a tenant creates for this mode.
     */
    public function recordType(): string
    {
        return match ($this) {
            self::Cname => 'CNAME',
            self::ARecord => 'A',
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
