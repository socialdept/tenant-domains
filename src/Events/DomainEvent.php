<?php

namespace SocialDept\TenantDomains\Events;

use Illuminate\Database\Eloquent\Model;

/**
 * Base for the domain lifecycle events.
 *
 * These are the seam between the package and whatever a host app does about a
 * domain changing state. One source app dispatched a server migration inline
 * from its verification loop and the other sent mail. Neither belongs in a
 * package, and both are one listener.
 */
abstract class DomainEvent
{
    public function __construct(public readonly Model $domain)
    {
        //
    }

    public function fqdn(): string
    {
        return (string) $this->domain->fqdn;
    }
}
