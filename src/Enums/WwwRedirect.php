<?php

namespace SocialDept\TenantDomains\Enums;

use SocialDept\TenantDomains\Core\DomainName;

/**
 * How an apex domain treats its `www.` host.
 *
 * Only meaningful at the apex. A tenant whose domain is already a subdomain has
 * no `www.` to decide about, and `www.blog.example.com` is a name nobody asks
 * for.
 *
 * Null on the column means the tenant has not chosen, which behaves as
 * {@see self::None}: the apex alone is served, and the `www.` host is as
 * unknown as any other stray name. That is deliberate rather than a default of
 * convenience, because serving a host means getting a certificate for it, and a
 * certificate nobody asked for still spends the platform's rate limit.
 */
enum WwwRedirect: string
{
    /** `www.example.com` is served and redirects to `example.com`. */
    case FromWww = 'from_www';

    /** `example.com` is served and redirects to `www.example.com`, which becomes the address. */
    case ToWww = 'to_www';

    /** Only `example.com` is served. */
    case None = 'none';

    /**
     * Whether the `www.` host is served at all, and so needs a routing record,
     * a certificate, and a row that answers for it.
     */
    public function servesWww(): bool
    {
        return $this !== self::None;
    }

    /**
     * Whether `www.` is the address visitors should end up at.
     */
    public function canonicalIsWww(): bool
    {
        return $this === self::ToWww;
    }

    /**
     * The address visitors should see, given the domain's own name.
     *
     * Never prefixes a host that is already a `www.`, and never prefixes a
     * subdomain, so a mode set on a row it does not apply to is inert rather
     * than wrong.
     */
    public function canonicalHostFor(DomainName|string $domain): string
    {
        $domain = $domain instanceof DomainName ? $domain : DomainName::make($domain);

        return $this->canonicalIsWww() && $domain->isApex()
            ? $domain->www()
            : $domain->value;
    }

    /**
     * Resolve a column that may be null into the mode it behaves as.
     */
    public static function fromColumn(self|string|null $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        return $value === null ? self::None : (self::tryFrom($value) ?? self::None);
    }
}
