<?php

namespace SocialDept\TenantDomains\Contracts;

use SocialDept\TenantDomains\Data\DnsRecord;

/**
 * Manages records in *our* zone, meaning the platform domain.
 *
 * Never the tenant's zone. Everything a tenant must create, they create
 * themselves. This exists for the records we own: the CNAME target custom
 * domains point at, platform subdomains, and anything under the delegation
 * suffix.
 */
interface ZoneDriver
{
    /**
     * Create or replace a record, matched by type and name. Idempotent.
     */
    public function upsert(DnsRecord $record): bool;

    public function delete(string $type, string $name): bool;

    public function find(string $type, string $name): ?DnsRecord;

    /**
     * Whether the driver has everything it needs to make changes. A driver that
     * is not configured must report so rather than failing at the first write.
     */
    public function configured(): bool;
}
