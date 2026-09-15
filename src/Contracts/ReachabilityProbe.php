<?php

namespace SocialDept\TenantDomains\Contracts;

use SocialDept\TenantDomains\Data\ReachabilityResult;

/**
 * Proves that a request to a domain actually arrives at the right origin.
 *
 * The host app supplies this, because only it knows what a correct response
 * looks like. The default implementation asks for a path and accepts any 2xx
 * that is not a redirect. That catches the common failure, a redirect rule or
 * worker intercepting the domain, without knowing anything about the app.
 */
interface ReachabilityProbe
{
    /**
     * @param  array<int, string>  $urls  One per job the domain does.
     */
    public function probe(array $urls): ReachabilityResult;
}
