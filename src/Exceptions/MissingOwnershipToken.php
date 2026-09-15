<?php

namespace SocialDept\TenantDomains\Exceptions;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use SocialDept\TenantDomains\Contracts\ProvidesOwnershipToken;

/**
 * Nothing could supply the token a domain's TXT record must carry.
 */
class MissingOwnershipToken extends RuntimeException
{
    public static function for(Model $domain, string $relation): self
    {
        return new self(sprintf(
            'No ownership token for [%s]. Implement %s on your tenant model, or on the domain model itself. Looked for a [%s] relation on %s. Set tenant-domains.tenant_relation if yours is named differently.',
            $domain->fqdn ?? $domain->getKey(),
            ProvidesOwnershipToken::class,
            $relation,
            $domain::class,
        ));
    }
}
