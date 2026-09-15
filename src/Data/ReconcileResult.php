<?php

namespace SocialDept\TenantDomains\Data;

use SocialDept\TenantDomains\Enums\ReconcileOutcome;

/**
 * What one pass over one domain did.
 */
final class ReconcileResult
{
    public function __construct(
        public readonly ReconcileOutcome $outcome,
        public readonly ?string $detail = null,
    ) {
        //
    }

    public static function advanced(string $detail): self
    {
        return new self(ReconcileOutcome::Advanced, $detail);
    }

    public static function verified(string $method): self
    {
        return new self(ReconcileOutcome::Verified, "verified via {$method}");
    }

    public static function waiting(string $detail): self
    {
        return new self(ReconcileOutcome::Waiting, $detail);
    }

    public static function unavailable(string $detail): self
    {
        return new self(ReconcileOutcome::Unavailable, $detail);
    }

    public static function skipped(string $detail): self
    {
        return new self(ReconcileOutcome::Skipped, $detail);
    }

    public function changedSomething(): bool
    {
        return $this->outcome === ReconcileOutcome::Advanced
            || $this->outcome === ReconcileOutcome::Verified;
    }
}
