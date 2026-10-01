<?php

namespace SocialDept\TenantDomains\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use SocialDept\TenantDomains\Models\Concerns\IsCustomDomain;

/**
 * A host app's domain row, reduced to the columns the package itself reads.
 *
 * Exists so the trait is tested as a model rather than as a set of closures, and
 * so a test asserting on a binding goes through the same accessors production
 * does.
 */
class TestDomain extends Model
{
    use IsCustomDomain;

    protected $table = 'domains';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return $this->customDomainCasts();
    }
}
