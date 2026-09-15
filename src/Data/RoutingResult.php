<?php

namespace SocialDept\TenantDomains\Data;

/**
 * Whether a domain's traffic points at us, and what was seen if not.
 *
 * `method` names which shape satisfied the check, because the tenant-facing
 * message has to talk about the record they were actually told to create.
 * Telling someone on the A-record path that "the CNAME is missing" sends them
 * looking for a record the instructions never mentioned.
 */
final class RoutingResult
{
    /**
     * @param  'cname'|'a_record'|null  $method
     * @param  array<int, string>  $expectedIps
     */
    public function __construct(
        public readonly bool $verified,
        public readonly ?string $method = null,
        public readonly ?string $resolved = null,
        public readonly ?string $expectedCname = null,
        public readonly array $expectedIps = [],
        public readonly ?string $hint = null,
        public readonly bool $proxied = false,
    ) {
        //
    }

    public static function verified(string $method, ?string $resolved = null): self
    {
        return new self(verified: true, method: $method, resolved: $resolved);
    }

    /**
     * @param  array<int, string>  $expectedIps
     */
    public static function failed(
        ?string $resolved,
        ?string $expectedCname,
        array $expectedIps = [],
        ?string $hint = null,
    ): self {
        return new self(
            verified: false,
            resolved: $resolved,
            expectedCname: $expectedCname,
            expectedIps: $expectedIps,
            hint: $hint,
        );
    }

    /**
     * The records exist but sit behind a proxy, so their target is invisible.
     *
     * Deliberately not `verified`. Nothing has been proven, and the caller has to
     * decide whether it has another way to settle the question. A real request is
     * the only one that works, so anything without a reachability probe should
     * treat this as unresolved rather than waving it through.
     *
     * @param  array<int, string>  $expectedIps
     */
    public static function proxied(
        ?string $resolved,
        ?string $expectedCname,
        array $expectedIps = [],
        ?string $hint = null,
    ): self {
        return new self(
            verified: false,
            resolved: $resolved,
            expectedCname: $expectedCname,
            expectedIps: $expectedIps,
            hint: $hint,
            proxied: true,
        );
    }

    /**
     * Whether something answers at this name, but not us.
     *
     * Worth distinguishing: an existing wrong record usually means an old host
     * the tenant has to remove, where no record at all just means they have not
     * started.
     */
    public function resolvesElsewhere(): bool
    {
        return ! $this->verified && ! $this->proxied && $this->resolved !== null;
    }

    public function isProxied(): bool
    {
        return $this->proxied;
    }
}
