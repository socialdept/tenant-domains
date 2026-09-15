<?php

namespace SocialDept\TenantDomains\Drivers\Zone;

use SocialDept\TenantDomains\Contracts\ZoneDriver;
use SocialDept\TenantDomains\Data\DnsRecord;

/**
 * Does nothing, and says so.
 *
 * The default. A platform whose zone is managed by hand needs no zone driver at
 * all, and `configured()` returning false is what lets the readiness check tell
 * an operator which records to create themselves.
 */
class NullZoneDriver implements ZoneDriver
{
    public function upsert(DnsRecord $record): bool
    {
        return false;
    }

    public function delete(string $type, string $name): bool
    {
        return false;
    }

    public function find(string $type, string $name): ?DnsRecord
    {
        return null;
    }

    public function configured(): bool
    {
        return false;
    }
}
