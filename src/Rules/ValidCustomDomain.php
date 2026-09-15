<?php

namespace SocialDept\TenantDomains\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;
use SocialDept\TenantDomains\Core\DomainName;
use SocialDept\TenantDomains\Core\Platform;

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
        $domain = DomainName::make((string) $value);

        if (! str_contains($domain->value, '.')) {
            $fail('The :attribute must be a full domain name, like blog.example.com.');

            return;
        }

        if (! $domain->isValid()) {
            $fail('The :attribute is not a valid domain name.');

            return;
        }

        $platform = app(Platform::class);

        if ($domain->isUnder($platform->domain)) {
            $fail("The :attribute cannot be a {$platform->domain} address.");

            return;
        }

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
