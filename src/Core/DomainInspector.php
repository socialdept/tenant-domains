<?php

namespace SocialDept\TenantDomains\Core;

use SocialDept\TenantDomains\Data\DomainInspection;
use SocialDept\TenantDomains\Enums\WwwRedirect;

/**
 * Answers everything structural about a typed hostname, in one pass, offline.
 *
 * Reached through {@see \SocialDept\TenantDomains\Domains::inspect()}. Separate
 * from that front door because the rules here are pure and worth testing without
 * a container, and because {@see \SocialDept\TenantDomains\Rules\ValidCustomDomain}
 * needs the same verdicts without duplicating them.
 */
class DomainInspector
{
    public function __construct(private readonly Platform $platform)
    {
        //
    }

    public function inspect(string $input): DomainInspection
    {
        $name = DomainName::make($input);
        $host = $name->value;

        $isPlatformHost = $this->platform->domain !== ''
            && $name->isUnder($this->platform->domain);

        [$isValid, $reason] = $this->validate($name, $isPlatformHost);

        $isWww = $name->isWww();
        $withoutWww = $name->withoutWww();

        // A `www.` is only an alias when what is left is itself a registrable
        // apex. `www.blog.example.com` is a name in its own right, and treating it
        // as an alias would store a row the tenant never asked for.
        $isWwwAlias = $isWww && $withoutWww->isApex();

        $subject = $isWwwAlias ? $withoutWww : $name;
        $subjectIsApex = $subject->isApex();

        return new DomainInspection(
            input: $input,
            host: $host,
            isValid: $isValid,
            reason: $reason,
            isApex: $name->isApex(),
            registrableDomain: $name->registrableDomain(),
            recordPrefix: $name->recordPrefix(),
            isWww: $isWww,
            apex: $subjectIsApex ? $subject->value : $name->registrableDomain(),
            storeAs: $subject->value,
            wwwHost: $subjectIsApex ? $subject->www() : null,
            wwwIsChoosable: $isValid && $subjectIsApex,
            suggestedWwwRedirect: match (true) {
                ! $subjectIsApex => WwwRedirect::None,
                // They typed the `www.` host, so that is the address they want.
                $isWwwAlias => WwwRedirect::ToWww,
                default => WwwRedirect::FromWww,
            },
            isPlatformHost: $isPlatformHost,
        );
    }

    /**
     * @return array{0: bool, 1: string|null}
     */
    private function validate(DomainName $name, bool $isPlatformHost): array
    {
        if ($name->value === '') {
            return [false, 'Enter a domain name.'];
        }

        if (! str_contains($name->value, '.')) {
            return [false, 'Enter a full domain name, like blog.example.com.'];
        }

        if (! $name->isValid()) {
            return [false, 'That is not a valid domain name.'];
        }

        // Ours to hand out as subdomains. Letting a tenant register one as a
        // "custom" domain would let them claim another tenant's address.
        if ($isPlatformHost) {
            return [false, "That domain belongs to {$this->platform->domain}."];
        }

        return [true, null];
    }
}
