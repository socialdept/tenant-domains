<?php

namespace SocialDept\TenantDomains\Models;

use Illuminate\Database\Eloquent\Model;
use SocialDept\TenantDomains\Models\Concerns\IsCustomDomain;

/**
 * A ready-made domain model for apps not already carrying one.
 *
 * Apps on stancl/tenancy should keep extending
 * `Stancl\Tenancy\Database\Models\Domain` and mix in {@see IsCustomDomain}
 * instead. The package must not compete for that slot, and stancl's model carries
 * tenancy behaviour this one does not.
 */
class Domain extends Model
{
    use IsCustomDomain;

    protected $guarded = [];

    public function getTable(): string
    {
        return $this->table ?? config('tenant-domains.table', 'domains');
    }

    protected function casts(): array
    {
        return $this->customDomainCasts();
    }
}
