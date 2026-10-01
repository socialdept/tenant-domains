<?php

namespace SocialDept\TenantDomains\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use SocialDept\TenantDomains\Core\DomainName;
use SocialDept\TenantDomains\Core\Platform;
use SocialDept\TenantDomains\Enums\DomainStatus;
use SocialDept\TenantDomains\Enums\RoutingMode;
use SocialDept\TenantDomains\Enums\WwwRedirect;

/**
 * Everything a domain row needs to take part in custom-domain setup.
 *
 * A trait rather than a base class so it composes with whatever the host app's
 * model already extends, which in practice is
 * `Stancl\Tenancy\Database\Models\Domain`.
 *
 * @property string $domain
 * @property string|null $platform_base
 * @property DomainStatus $status
 * @property RoutingMode $routing_mode
 * @property WwwRedirect|null $www_redirect
 * @property string|null $acme_delegation_id
 */
trait IsCustomDomain
{
    /**
     * Merge into the model's own `casts()`.
     *
     * @return array<string, string>
     */
    public function customDomainCasts(): array
    {
        return [
            'status' => DomainStatus::class,
            'routing_mode' => RoutingMode::class,
            'www_redirect' => WwwRedirect::class,
            'is_primary' => 'boolean',
            'ownership_verified_at' => 'datetime',
            'verified_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * @return array<int, string>
     */
    public function customDomainFillable(): array
    {
        return [
            'domain',
            'platform_base',
            'tenant_id',
            'status',
            'routing_mode',
            'www_redirect',
            'is_primary',
            'acme_delegation_id',
            'ownership_verified_at',
            'verified_at',
            'last_checked_at',
            'last_failure_reason',
            'metadata',
        ];
    }

    /* Accessors
     * - - - - - - - - - - - - - */

    /**
     * Whether this is a platform subdomain rather than a tenant's own domain.
     *
     * Read from the column, never from whether `domain` contains a dot. The
     * heuristic works only while there is exactly one platform base domain, and
     * misfiles every subdomain the moment a second one is offered.
     */
    protected function isPlatformSubdomain(): Attribute
    {
        return Attribute::get(fn (): bool => $this->platform_base !== null);
    }

    protected function isCustom(): Attribute
    {
        return Attribute::get(fn (): bool => $this->platform_base === null);
    }

    /**
     * The full hostname. A platform subdomain gets its base appended, and a custom
     * domain is already whole.
     */
    protected function fqdn(): Attribute
    {
        return Attribute::get(fn (): string => $this->is_platform_subdomain
            ? $this->domain.'.'.$this->platform_base
            : $this->domain);
    }

    protected function name(): Attribute
    {
        return Attribute::get(fn (): DomainName => DomainName::make($this->fqdn));
    }

    protected function isApex(): Attribute
    {
        return Attribute::get(fn (): bool => $this->is_custom && $this->name->isApex());
    }

    /**
     * The Name field a tenant's DNS provider expects for the root record.
     */
    protected function recordPrefix(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->is_custom ? $this->name->recordPrefix() : null);
    }

    /**
     * Where DNS-01 challenges for this domain are written, inside our zone.
     */
    protected function acmeDelegationTarget(): Attribute
    {
        return Attribute::get(function (): ?string {
            if ($this->acme_delegation_id === null) {
                return null;
            }

            return app(Platform::class)->delegationTarget($this->acme_delegation_id);
        });
    }

    /**
     * Whether the domain is in service. Platform subdomains always are, because
     * their DNS is ours and there is nothing for a tenant to prove.
     */
    protected function isVerified(): Attribute
    {
        return Attribute::get(fn (): bool => $this->is_platform_subdomain
            || $this->status === DomainStatus::Verified);
    }

    /**
     * Whether the edge should serve this domain and hold a certificate for it.
     *
     * Wider than `is_verified`: a domain being confirmed has to be reachable
     * before it can be confirmed.
     */
    protected function isRoutable(): Attribute
    {
        return Attribute::get(fn (): bool => $this->is_platform_subdomain
            || $this->status->isRoutable());
    }

    /* www
     * - - - - - - - - - - - - - */

    /**
     * How this domain treats its `www.` host, with a null column read as
     * {@see WwwRedirect::None}.
     */
    protected function wwwMode(): Attribute
    {
        return Attribute::get(fn (): WwwRedirect => WwwRedirect::fromColumn($this->www_redirect));
    }

    /**
     * The `www.` hostname, or null where the concept does not apply: a platform
     * subdomain, or a custom domain that is not an apex.
     */
    protected function wwwHost(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->is_apex ? $this->name->www() : null);
    }

    /**
     * Whether the edge should serve the `www.` host as well as the domain itself.
     */
    protected function servesWww(): Attribute
    {
        return Attribute::get(fn (): bool => $this->is_apex && $this->www_mode->servesWww());
    }

    /**
     * The address a visitor should end up at.
     *
     * Distinct from {@see self::fqdn()} on purpose. `fqdn` is the domain's
     * identity in DNS, and every record this package asks a tenant to create is
     * computed relative to it: an ownership TXT belongs at `_verify.example.com`
     * and an apex A record at `@`, whichever host visitors are redirected to. A
     * host app building public URLs wants this one, so a link does not 301 on
     * every request.
     */
    protected function canonicalHost(): Attribute
    {
        return Attribute::get(fn (): string => $this->is_apex
            ? $this->www_mode->canonicalHostFor($this->name)
            : $this->fqdn);
    }

    protected function ownsOwnership(): Attribute
    {
        return Attribute::get(fn (): bool => $this->ownership_verified_at !== null);
    }

    /* Scopes
     * - - - - - - - - - - - - - */

    /**
     * @param  Builder<static>  $query
     */
    public function scopeCustom(Builder $query): void
    {
        $query->whereNull('platform_base');
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopePlatform(Builder $query): void
    {
        $query->whereNotNull('platform_base');
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeWithStatus(Builder $query, DomainStatus ...$statuses): void
    {
        $query->whereIn('status', array_column($statuses, 'value'));
    }

    /**
     * Rows that answer for a hostname: the row whose name it is, plus the apex
     * carrying it as its `www.` alias.
     *
     * More than one row can match, and the caller decides which wins. A tenant
     * who has attached `www.example.com` as a domain in its own right must not be
     * shadowed by a different tenant's apex that happens to serve www, so order
     * the exact match first.
     *
     * Custom domains only. Platform subdomains are stored as a bare label, so a
     * caller that serves those looks them up by stripping the platform base.
     *
     * @param  Builder<static>  $query
     */
    public function scopeServingHost(Builder $query, DomainName|string $host): void
    {
        $name = $host instanceof DomainName ? $host : DomainName::make($host);
        $apex = $name->withoutWww();

        // Only an apex has a www alias to be. Without this, `www.blog.example.com`
        // would match a `blog.example.com` row that never opted in.
        $isWwwAlias = $name->isWww() && $apex->isApex();

        $query->where(function (Builder $query) use ($name, $apex, $isWwwAlias): void {
            $query->where('domain', $name->value);

            if (! $isWwwAlias) {
                return;
            }

            $query->orWhere(function (Builder $query) use ($apex): void {
                $query->where('domain', $apex->value)
                    ->whereNull('platform_base')
                    ->whereIn('www_redirect', [
                        WwwRedirect::FromWww->value,
                        WwwRedirect::ToWww->value,
                    ]);
            });
        });
    }

    /**
     * {@see self::scopeServingHost()}, with the row whose name the host actually
     * is ordered ahead of any apex serving it as an alias.
     *
     * @param  Builder<static>  $query
     */
    public function scopeServingHostByPrecedence(Builder $query, DomainName|string $host): void
    {
        $name = $host instanceof DomainName ? $host : DomainName::make($host);

        $query->servingHost($name)
            ->orderByRaw('CASE WHEN domain = ? THEN 0 ELSE 1 END', [$name->value]);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeRoutable(Builder $query): void
    {
        $query->where(function (Builder $query): void {
            $query->whereNotNull('platform_base')
                ->orWhereIn('status', [DomainStatus::Verified->value, DomainStatus::Confirming->value]);
        });
    }
}
