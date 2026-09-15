<?php

namespace SocialDept\TenantDomains\Exceptions;

use RuntimeException;

/**
 * A DNS lookup could not be made at all.
 *
 * Deliberately distinct from a lookup that succeeded and found nothing. Both
 * apps this package was extracted from collapsed the two, so a resolver outage
 * was indistinguishable from a tenant who had not added their record yet, and
 * the setup UI blamed the tenant for our failure.
 */
class DnsUnavailable extends RuntimeException
{
    public function __construct(public readonly string $host, string $message)
    {
        parent::__construct($message);
    }

    public static function forHost(string $host): self
    {
        return new self($host, "DNS lookups are failing, so the records for {$host} could not be checked.");
    }

    /**
     * Phrased for the tenant: this is our problem, not their record.
     */
    public function userMessage(): string
    {
        return 'We could not check DNS just now, so this is not a verdict on your records. Try again in a moment.';
    }
}
