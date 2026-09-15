<?php

namespace SocialDept\TenantDomains\Data;

/**
 * Whether a real request to a domain arrived where it should have.
 *
 * `reason` is written for the tenant. It names the thing they have to change, a
 * redirect rule or a page rule or a worker, rather than restating that something
 * went wrong.
 */
final class ReachabilityResult
{
    public function __construct(
        public readonly bool $reachable,
        public readonly ?int $status = null,
        public readonly ?string $reason = null,
        public readonly ?string $url = null,
    ) {
        //
    }

    public static function ok(?int $status = null, ?string $url = null): self
    {
        return new self(reachable: true, status: $status, url: $url);
    }

    public static function failed(string $reason, ?int $status = null, ?string $url = null): self
    {
        return new self(reachable: false, status: $status, reason: $reason, url: $url);
    }
}
