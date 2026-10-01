<?php

namespace SocialDept\TenantDomains\Data;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Where a hostname sits relative to its registrable domain.
 *
 * Decided by the Public Suffix List, never by counting dots: this is what says
 * whether a tenant is told to create a CNAME or an A record, and whether the
 * `www.` question applies to them at all.
 *
 * @implements Arrayable<string, mixed>
 */
final class ApexFacts implements Arrayable
{
    public function __construct(
        /** The host is itself a registrable apex (`example.co.uk`), not a subdomain of one. */
        public readonly bool $isApex,
        /** The zone a tenant actually edits. `example.co.uk` for `blog.example.co.uk`. */
        public readonly ?string $registrableDomain,
        /** The labels below it, which is what a DNS provider's Name field wants. Null at the apex. */
        public readonly ?string $recordPrefix,
    ) {
        //
    }

    /**
     * @return array{is_apex: bool, registrable_domain: string|null, record_prefix: string|null}
     */
    public function toArray(): array
    {
        return [
            'is_apex' => $this->isApex,
            'registrable_domain' => $this->registrableDomain,
            'record_prefix' => $this->recordPrefix,
        ];
    }
}
