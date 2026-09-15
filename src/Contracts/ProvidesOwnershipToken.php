<?php

namespace SocialDept\TenantDomains\Contracts;

/**
 * A tenant that can prove it owns a domain.
 *
 * Implemented on the host app's tenant model. The token ends up in a public TXT
 * record, so it has to be stable for the life of the tenant. A rotating value
 * invalidates every domain already verified against it. It must also not be
 * guessable from the tenant's public identity, or one tenant could claim a
 * domain pointed at another.
 */
interface ProvidesOwnershipToken
{
    public function domainOwnershipToken(): string;
}
