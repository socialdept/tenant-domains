<?php

namespace SocialDept\TenantDomains\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;
use SocialDept\TenantDomains\Core\DomainName;
use SocialDept\TenantDomains\Domains;

/**
 * A hostname a tenant may actually claim.
 *
 * Refuses the platform's own domain and anything under it: those are ours to
 * hand out as subdomains, and letting a tenant register one as a "custom" domain
 * would let them claim another tenant's address.
 */
class ValidCustomDomain implements ValidationRule
{
    public function __construct(
        /** Skip the uniqueness check for this row, when editing an existing domain. */
        private readonly int|string|null $ignoreId = null,
    ) {
        //
    }

    /**
     * @param  Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // The structural verdicts come from the inspector, so a form that calls
        // `Domains::inspect()` while the tenant types can never disagree with the
        // rule that runs on submit.
        $inspection = app(Domains::class)->inspect((string) $value);

        if (! $inspection->valid->ok) {
            $fail(str_replace('That ', 'The :attribute ', (string) $inspection->valid->error));

            return;
        }

        $domain = DomainName::make($inspection->domain);

        if ($this->alreadyTaken($domain)) {
            // Never "belongs to another account", which leaks who is hosted here.
            $fail('The :attribute is already in use.');
        }
    }

    private function alreadyTaken(DomainName $domain): bool
    {
        $query = DB::table((string) config('tenant-domains.table', 'domains'))
            ->where('domain', $domain->value);

        if ($this->ignoreId !== null) {
            $query->where('id', '!=', $this->ignoreId);
        }

        return $query->exists();
    }
}
