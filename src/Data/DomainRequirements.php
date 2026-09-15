<?php

namespace SocialDept\TenantDomains\Data;

/**
 * What a domain is actually for, reduced to the only two questions this package
 * needs to answer.
 *
 * The generalisation of one source app's `DomainRole` (pds / handles / both).
 * The package does not need to know what a "PDS" or a "handle suffix" is. It only
 * needs to know whether this domain needs a root routing record and whether it
 * needs a wildcard certificate. Everything else is the host app's vocabulary.
 *
 * The default is the common case: route the domain, no wildcard.
 */
final class DomainRequirements
{
    public function __construct(
        /** Needs a root record, because traffic for the domain itself lands here. */
        public readonly bool $root = true,
        /** Needs a wildcard record and certificate, for per-account hostnames. */
        public readonly bool $wildcard = false,
    ) {
        //
    }

    public static function default(): self
    {
        return new self();
    }

    /**
     * Attached but serving nothing. A domain kept so the name stays reserved.
     * Nothing to verify, nothing to route, nothing to certify.
     */
    public static function none(): self
    {
        return new self(root: false, wildcard: false);
    }

    public function servesAnything(): bool
    {
        return $this->root || $this->wildcard;
    }

    /**
     * @return array{root: bool, wildcard: bool}
     */
    public function toArray(): array
    {
        return ['root' => $this->root, 'wildcard' => $this->wildcard];
    }
}
