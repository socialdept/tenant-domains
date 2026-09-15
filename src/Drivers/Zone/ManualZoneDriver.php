<?php

namespace SocialDept\TenantDomains\Drivers\Zone;

use Illuminate\Support\Facades\Log;
use SocialDept\TenantDomains\Contracts\ZoneDriver;
use SocialDept\TenantDomains\Data\DnsRecord;

/**
 * Logs the record a human has to create, instead of creating it.
 *
 * For a platform whose own zone is not API-managed. Reports itself as configured
 * so the rest of the package proceeds normally. The only difference is that the
 * record appears in the log rather than in the zone.
 */
class ManualZoneDriver implements ZoneDriver
{
    public function upsert(DnsRecord $record): bool
    {
        Log::info('[tenant-domains] Create this record in your zone by hand', $record->toArray());

        return true;
    }

    public function delete(string $type, string $name): bool
    {
        Log::info('[tenant-domains] Remove this record from your zone by hand', [
            'type' => $type,
            'name' => $name,
        ]);

        return true;
    }

    public function find(string $type, string $name): ?DnsRecord
    {
        return null;
    }

    public function configured(): bool
    {
        return true;
    }
}
