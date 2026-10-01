<?php

namespace SocialDept\TenantDomains\Data;

use Illuminate\Contracts\Support\Arrayable;
use SocialDept\TenantDomains\Enums\WwwRedirect;

/**
 * Whether the `www.` question applies to this hostname, and what to preselect.
 *
 * `supported` is the one a form reads: only an apex has a `www.` worth serving,
 * so asking anywhere else offers a choice the server will discard.
 *
 * @implements Arrayable<string, mixed>
 */
final class WwwFacts implements Arrayable
{
    public function __construct(
        /** The hostname as typed is itself a `www.` host. */
        public readonly bool $isWww,
        /** The `www.` host worth serving, or null where the concept does not apply. */
        public readonly ?string $host,
        /** Whether it is meaningful to ask the tenant about `www.` at all. */
        public readonly bool $supported,
        /** The answer to preselect, given what they typed. */
        public readonly WwwRedirect $defaultRedirect,
    ) {
        //
    }

    /**
     * @return array{is_www: bool, host: string|null, supported: bool, default_redirect: string}
     */
    public function toArray(): array
    {
        return [
            'is_www' => $this->isWww,
            'host' => $this->host,
            'supported' => $this->supported,
            'default_redirect' => $this->defaultRedirect->value,
        ];
    }
}
