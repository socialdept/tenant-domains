<?php

namespace SocialDept\TenantDomains\Events;

use Illuminate\Database\Eloquent\Model;

/**
 * The domain is proven, routed, reachable and in service.
 *
 * The event to hang "tell the owner" and "finish provisioning" on. Fires once
 * per transition, not once per check. Reconciliation runs every few minutes, and
 * a listener that mails on every pass will mail forever.
 */
class DomainVerified extends DomainEvent
{
    public function __construct(Model $domain, public readonly string $method)
    {
        parent::__construct($domain);
    }
}
