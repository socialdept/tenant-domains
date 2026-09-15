<?php

namespace SocialDept\TenantDomains\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use SocialDept\TenantDomains\Core\DomainName;
use SocialDept\TenantDomains\Core\Platform;
use SocialDept\TenantDomains\Enums\DomainStatus;
use SocialDept\TenantDomains\Enums\RoutingMode;

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
