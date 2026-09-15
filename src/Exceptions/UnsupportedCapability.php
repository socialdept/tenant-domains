<?php

namespace SocialDept\TenantDomains\Exceptions;

use RuntimeException;
use SocialDept\TenantDomains\Enums\IngressCapability;

/**
 * The configured edge cannot do something the app needs.
 *
 * Thrown rather than logged because the alternative is worse: a wildcard that
 * never gets a certificate looks like success everywhere except the one place
 * nobody checks until a tenant's hostnames stop resolving.
 */
class UnsupportedCapability extends RuntimeException
{
    public static function for(IngressCapability $capability, string $driver): self
    {
        return new self(sprintf(
            'The configured ingress driver [%s] does not support %s. %s',
            class_basename($driver),
            $capability->describe(),
            $capability === IngressCapability::Wildcard
                ? 'Wildcard certificates require DNS-01, so set tenant-domains.certificates.mode to delegated_dns.'
                : '',
        ));
    }
}
