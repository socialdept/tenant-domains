<?php

namespace SocialDept\TenantDomains\Contracts;

use Illuminate\Database\Eloquent\Model;
use SocialDept\TenantDomains\Data\DomainRequirements;

/**
 * Says what a given domain is for.
 *
 * Optional. Without a binding the package assumes every domain needs a root
 * record and no wildcard, which is the common case and what a single-origin app
 * wants.
 *
 * Bind an implementation when domains do different jobs, one serving the app
 * itself and another only carrying per-account hostnames, so the records a
 * tenant is asked to create differ accordingly.
 */
interface ResolvesDomainRequirements
{
    public function requirementsFor(Model $domain): DomainRequirements;
}
