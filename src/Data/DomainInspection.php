<?php

namespace SocialDept\TenantDomains\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use SocialDept\TenantDomains\Core\DomainName;
use SocialDept\TenantDomains\Enums\WwwRedirect;

/**
 * Everything structural a host app can know about a hostname before it stores it.
 *
 * Exists because the alternative is every client re-deriving it. One source app
 * shipped a twenty-entry suffix table to its JavaScript so a form could decide
 * whether to ask about `www.`, and it disagreed with the server for every
 * registrable suffix outside that list.
 *
 * Deliberately **offline**: the Public Suffix List and string rules only, no DNS
 * and no database. That is what makes it safe to call on a keystroke. Questions
 * that need the network live elsewhere: {@see \SocialDept\TenantDomains\Domains::recommendRoutingMode()}
 * reads nameservers, and uniqueness is the host app's, since only it knows which
 * rows count as taken.
 *
 * @implements Arrayable<string, mixed>
 */
final class DomainInspection implements Arrayable, JsonSerializable
{
    public function __construct(
        /** The hostname exactly as it was handed in. */
        public readonly string $input,
        /** Lowercased, trimmed, scheme and trailing dot removed. */
        public readonly string $host,
        /** Syntactically usable as a custom domain, and not one of ours. */
        public readonly bool $isValid,
        /** Why not, in words a form can show. Null when valid. */
        public readonly ?string $reason,
        /** A registrable apex (`example.co.uk`) rather than a subdomain of one. */
        public readonly bool $isApex,
        public readonly ?string $registrableDomain,
        /** The labels below the registrable domain, which is what a DNS Name field wants. */
        public readonly ?string $recordPrefix,
        public readonly bool $isWww,
        /**
         * The registrable apex this host belongs to, which is the row that should
         * exist. `www.example.com` and `example.com` both yield `example.com`.
         */
        public readonly ?string $apex,
        /**
         * The hostname a row should actually carry.
         *
         * A typed `www.example.com` is stored as its apex, because the two cannot
         * sensibly be separate rows: one of them has to own the certificate and
         * decide which way the redirect runs. Anything else is stored as typed,
         * including `www.blog.example.com`, which is nobody's alias.
         */
        public readonly string $storeAs,
        /** The `www.` host, when there is one worth serving. */
        public readonly ?string $wwwHost,
        /** Whether it is meaningful to ask the tenant about `www.` at all. */
        public readonly bool $wwwIsChoosable,
        /** The answer to preselect, given what they typed. */
        public readonly WwwRedirect $suggestedWwwRedirect,
        /** The platform's own domain, or something under it. Never a custom domain. */
        public readonly bool $isPlatformHost,
    ) {
        //
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'input' => $this->input,
            'host' => $this->host,
            'isValid' => $this->isValid,
            'reason' => $this->reason,
            'isApex' => $this->isApex,
            'registrableDomain' => $this->registrableDomain,
            'recordPrefix' => $this->recordPrefix,
            'isWww' => $this->isWww,
            'apex' => $this->apex,
            'storeAs' => $this->storeAs,
            'wwwHost' => $this->wwwHost,
            'wwwIsChoosable' => $this->wwwIsChoosable,
            'suggestedWwwRedirect' => $this->suggestedWwwRedirect->value,
            'isPlatformHost' => $this->isPlatformHost,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function name(): DomainName
    {
        return DomainName::make($this->host);
    }
}
