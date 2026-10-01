<?php

namespace SocialDept\TenantDomains\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use SocialDept\TenantDomains\Core\DomainName;

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
 * Grouped by the question being asked, and serialised snake_case, which is what a
 * Laravel app's other payloads look like.
 *
 * @implements Arrayable<string, mixed>
 */
final class DomainInspection implements Arrayable, JsonSerializable
{
    public function __construct(
        /** The hostname as typed, lowercased and trimmed, scheme and trailing dot removed. */
        public readonly string $host,
        /**
         * The hostname a row should actually carry.
         *
         * A typed `www.example.com` becomes its apex, because the two cannot
         * sensibly be separate rows: one of them has to own the certificate and
         * decide which way the redirect runs. Anything else is kept as typed,
         * including `www.blog.example.com`, which is nobody's alias.
         */
        public readonly string $domain,
        public readonly DomainValidity $valid,
        public readonly ApexFacts $apex,
        public readonly WwwFacts $www,
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
            'host' => $this->host,
            'domain' => $this->domain,
            'valid' => $this->valid->toArray(),
            'apex' => $this->apex->toArray(),
            'www' => $this->www->toArray(),
            'is_platform_host' => $this->isPlatformHost,
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
