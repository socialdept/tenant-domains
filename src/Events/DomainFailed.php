<?php

namespace SocialDept\TenantDomains\Events;

use Illuminate\Database\Eloquent\Model;

/**
 * A check ran and the domain was wrong.
 *
 * Not dispatched when DNS itself was unavailable. That is our failure rather than
 * the tenant's, and treating the two alike is what makes a status page blame a
 * tenant for an outage on our side.
 */
class DomainFailed extends DomainEvent
{
    public function __construct(Model $domain, public readonly string $reason)
    {
        parent::__construct($domain);
    }
}
