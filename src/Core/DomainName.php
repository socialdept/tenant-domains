<?php

namespace SocialDept\TenantDomains\Core;

use Stringable;

/**
 * A hostname, and the questions the setup flow needs to ask about it.
 *
 * Immutable and framework-free. Everything here is decided by the Public Suffix
 * List rather than by counting dots, because "is this an apex?" decides whether
 * a tenant is told to create a CNAME or an A record, and getting it wrong sends
 * them to a record their provider will not accept.
 */
final class DomainName implements Stringable
{
    public function __construct(public readonly string $value)
    {
        //
    }

    public static function make(string $host): self
    {
        return new self(self::normalise($host));
    }

    /**
     * Lowercased, trimmed, with any trailing root dot and leading scheme removed.
     */
    public static function normalise(string $host): string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $host) ?? $host;
        $host = explode('/', $host, 2)[0];

        return rtrim($host, '.');
    }

    /**
     * Whether this is a registrable apex (`example.com`, `example.co.uk`) rather
     * than a subdomain of one (`blog.example.com`).
     *
     * Only the apex ever needs the A-record alternative: a subdomain can always
     * take a plain CNAME, which every provider supports.
     */
    public function isApex(): bool
    {
        return $this->recordPrefix() === null;
    }

    /**
     * The labels below the registrable domain, or null at the apex.
     *
     * This is what a DNS provider expects in its Name field, since the provider
     * appends the zone itself. `blog.example.com` yields `blog`, so an ownership
     * record is entered as `_own.blog`, not `_own.blog.example.com`.
     */
    public function recordPrefix(): ?string
    {
        return PublicSuffixList::subDomain($this->value);
    }

    /**
     * The registrable domain this host belongs to, which is the zone a tenant edits.
     */
    public function registrableDomain(): ?string
    {
        return PublicSuffixList::registrableDomain($this->value);
    }

    /**
     * A record's Name field as the tenant's DNS provider expects it: relative to
     * the zone.
     *
     * At the apex a bare record is written `@` and a prefixed one keeps just its
     * own label. Below the apex both carry the subdomain, so `blog.example.com`
     * routes at `blog` and proves ownership at `_own.blog`.
     */
    public function recordName(?string $base = null): string
    {
        $prefix = $this->recordPrefix();

        if ($base === null) {
            return $prefix ?? '@';
        }

        return $prefix === null ? $base : "{$base}.{$prefix}";
    }

    /**
     * The same record as a fully qualified name, for looking it up.
     */
    public function recordHost(?string $base = null): string
    {
        return $base === null ? $this->value : "{$base}.{$this->value}";
    }

    /**
     * Whether this host is the given domain or sits beneath it.
     */
    public function isUnder(string $parent): bool
    {
        $parent = self::normalise($parent);

        return $this->value === $parent || str_ends_with($this->value, '.'.$parent);
    }

    /**
     * The wildcard form, for handle hosts and other per-account subdomains.
     */
    public function wildcard(): string
    {
        return '*.'.$this->value;
    }

    /**
     * This host with its leftmost label removed, or null at the apex.
     *
     * What the certificate-authority endpoint uses to ask "is this one account
     * label under a domain we serve?". `alice.blog.example.com` yields
     * `blog.example.com`.
     */
    public function parent(): ?self
    {
        if ($this->isApex()) {
            return null;
        }

        $rest = strstr($this->value, '.');

        return $rest === false ? null : new self(substr($rest, 1));
    }

    /**
     * Whether the hostname is syntactically usable as a custom domain: at least
     * two labels, valid characters, and within the DNS length limit.
     */
    public function isValid(): bool
    {
        if ($this->value === '' || strlen($this->value) > 253) {
            return false;
        }

        return (bool) preg_match(
            '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/',
            $this->value,
        );
    }

    public function equals(self|string $other): bool
    {
        $other = $other instanceof self ? $other->value : self::normalise($other);

        return $this->value === $other;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
