<?php

namespace SocialDept\TenantDomains\Core;

use SocialDept\TenantDomains\Data\ApexFacts;
use SocialDept\TenantDomains\Data\DomainInspection;
use SocialDept\TenantDomains\Data\DomainValidity;
use SocialDept\TenantDomains\Data\WwwFacts;
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

        $isPlatformHost = $this->platform->domain !== ''
            && $name->isUnder($this->platform->domain);

        $validity = $this->validate($name, $isPlatformHost);

        // A `www.` is only an alias when what is left is itself a registrable
        // apex. `www.blog.example.com` is a name in its own right, and treating it
        // as an alias would store a row the tenant never asked for.
        $withoutWww = $name->withoutWww();
        $isWwwAlias = $name->isWww() && $withoutWww->isApex();

        $subject = $isWwwAlias ? $withoutWww : $name;
        $subjectIsApex = $subject->isApex();

        return new DomainInspection(
            host: $name->value,
            domain: $subject->value,
            valid: $validity,
            apex: new ApexFacts(
                isApex: $name->isApex(),
                registrableDomain: $name->registrableDomain(),
                recordPrefix: $name->recordPrefix(),
            ),
            www: new WwwFacts(
                isWww: $name->isWww(),
                host: $subjectIsApex ? $subject->www() : null,
                supported: $validity->ok && $subjectIsApex,
                defaultRedirect: match (true) {
                    ! $subjectIsApex => WwwRedirect::None,
                    // They typed the `www.` host, so that is the address they want.
                    $isWwwAlias => WwwRedirect::ToWww,
                    default => WwwRedirect::FromWww,
                },
            ),
            isPlatformHost: $isPlatformHost,
        );
    }

    private function validate(DomainName $name, bool $isPlatformHost): DomainValidity
    {
        if ($name->value === '') {
            return DomainValidity::failed('Enter a domain name.');
        }

        if (! str_contains($name->value, '.')) {
            return DomainValidity::failed('Enter a full domain name, like blog.example.com.');
        }

        if (! $name->isValid()) {
            return DomainValidity::failed('That is not a valid domain name.');
        }

        // Ours to hand out as subdomains. Letting a tenant register one as a
        // "custom" domain would let them claim another tenant's address.
        if ($isPlatformHost) {
            return DomainValidity::failed("That domain belongs to {$this->platform->domain}.");
        }

        return DomainValidity::ok();
    }
}
