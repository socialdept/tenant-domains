<?php

namespace SocialDept\TenantDomains;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use SocialDept\TenantDomains\Actions\VerifyOwnership;
use SocialDept\TenantDomains\Actions\VerifyRouting;
use SocialDept\TenantDomains\Contracts\IngressDriver;
use SocialDept\TenantDomains\Contracts\ProvidesOwnershipToken;
use SocialDept\TenantDomains\Contracts\ResolvesDomainRequirements;
use SocialDept\TenantDomains\Core\DelegationId;
use SocialDept\TenantDomains\Core\Detection\ProviderDetector;
use SocialDept\TenantDomains\Core\DomainName;
use SocialDept\TenantDomains\Core\Platform;
use SocialDept\TenantDomains\Data\DomainRequirements;
use SocialDept\TenantDomains\Data\HostnameBinding;
use SocialDept\TenantDomains\Data\Instructions;
use SocialDept\TenantDomains\Data\RoutingResult;
use SocialDept\TenantDomains\Enums\IngressCapability;
use SocialDept\TenantDomains\Enums\RoutingMode;
use SocialDept\TenantDomains\Exceptions\MissingOwnershipToken;
use SocialDept\TenantDomains\Exceptions\UnsupportedCapability;

/**
 * The package's front door.
 *
 * Everything a host app normally needs, in one place, so the actions and drivers
 * stay an implementation detail it can reach past only when it wants to.
 */
class Domains
{
    /**
     * How the ask endpoint decides whether a hostname may get a certificate.
     *
     * @var (Closure(DomainName): bool)|null
     */
    private static ?Closure $certificateAuthorization = null;

    public function __construct(
        private readonly Platform $platform,
        private readonly ProviderDetector $providers,
        private readonly VerifyOwnership $ownership,
        private readonly VerifyRouting $routing,
        private readonly IngressDriver $ingress,
    ) {
        //
    }

    public function platform(): Platform
    {
        return $this->platform;
    }

    /* Setup
     * - - - - - - - - - - - - - */

    /**
     * The records a tenant must create for a domain, and the context to explain
     * them.
     */
    public function instructionsFor(Model $domain): Instructions
    {
        $name = DomainName::make((string) $domain->fqdn);
        $detection = $this->providers->detect($name);

        return Instructions::build(
            domain: $name,
            platform: $this->platform,
            requirements: $this->requirementsFor($domain),
            routingMode: $domain->routing_mode ?? $this->recommendRoutingMode($name),
            ownershipToken: $this->ownershipTokenFor($domain),
            delegationId: $domain->acme_delegation_id,
            providerName: $detection['provider'],
            isCloudflare: $detection['is_cloudflare'],
            supportsApexCname: $detection['supports_apex_cname'],
        );
    }

    /**
     * Which routing record to recommend, from the domain's nameservers.
     *
     * A recommendation only, and a tenant can always switch. Subdomains always take
     * a CNAME. An apex on an unrecognised provider is steered to an A record,
     * because that works everywhere while an apex CNAME needs flattening the
     * provider may not offer.
     */
    public function recommendRoutingMode(DomainName|string $domain): RoutingMode
    {
        return $this->providers->recommendRoutingMode($domain);
    }

    /**
     * Give a domain a delegation id, if it does not already have one and the
     * certificate mode needs one.
     *
     * Deliberately never overwrites. The tenant may already have
     * `_acme-challenge` pointed at the existing id, and re-minting silently
     * invalidates their DNS.
     */
    public function ensureDelegationId(Model $domain): ?string
    {
        if (! $this->platform->certificateMode->needsDelegationRecord()) {
            return null;
        }

        if (($existing = $domain->acme_delegation_id) !== null && $existing !== '') {
            return $existing;
        }

        $id = DelegationId::generate(
            (int) config('tenant-domains.certificates.id_length', 12),
            (string) config('tenant-domains.certificates.id_alphabet', DelegationId::DEFAULT_ALPHABET),
        );

        $domain->forceFill(['acme_delegation_id' => $id])->save();

        return $id;
    }

    /* Verification
     * - - - - - - - - - - - - - */

    /**
     * Whether the tenant has proved they control this domain.
     *
     * @throws \SocialDept\TenantDomains\Exceptions\DnsUnavailable
     */
    public function verifyOwnership(Model $domain): bool
    {
        return ($this->ownership)(
            DomainName::make((string) $domain->fqdn),
            $this->ownershipTokenFor($domain),
        );
    }

    /**
     * Whether the domain's traffic points at us.
     *
     * @throws \SocialDept\TenantDomains\Exceptions\DnsUnavailable
     */
    public function verifyRouting(Model $domain): RoutingResult
    {
        return ($this->routing)(DomainName::make((string) $domain->fqdn));
    }

    /* Edge
     * - - - - - - - - - - - - - */

    /**
     * Tell the edge to serve this domain and get a certificate for it.
     *
     * @throws UnsupportedCapability if the domain needs a wildcard the
     *                               configured edge cannot issue.
     */
    public function publish(Model $domain, ?string $origin = null): void
    {
        $binding = $this->bindingFor($domain, $origin);

        if ($binding->isWildcard() && ! $this->ingress->supports(IngressCapability::Wildcard)) {
            throw UnsupportedCapability::for(IngressCapability::Wildcard, $this->ingress::class);
        }

        $this->ingress->publish($binding);
    }

    public function withdraw(Model $domain): void
    {
        $this->ingress->withdraw($this->bindingFor($domain));
    }

    public function certificateState(Model $domain): Data\CertificateState
    {
        return $this->ingress->certificateState((string) $domain->fqdn);
    }

    /**
     * The hostnames and delegation target the edge needs for this domain.
     */
    public function bindingFor(Model $domain, ?string $origin = null): HostnameBinding
    {
        $name = DomainName::make((string) $domain->fqdn);
        $requirements = $this->requirementsFor($domain);

        $hostnames = [];

        if ($requirements->root) {
            $hostnames[] = $name->value;
        }

        if ($requirements->wildcard) {
            $hostnames[] = $name->wildcard();
        }

        return new HostnameBinding(
            hostnames: $hostnames,
            acmeDelegationTarget: $domain->acme_delegation_target,
            origin: $origin,
            key: (string) ($domain->getKey() ?? $name->value),
        );
    }

    /**
     * The URLs to request when proving a domain is reachable, one per job it
     * does.
     *
     * A domain serving itself is asked at its own name. A domain carrying
     * per-account hostnames is asked at a **random** label, for the same reason
     * the wildcard record exists: only a wildcard route can answer for a name
     * nobody has created, so a single hand-made record cannot satisfy it.
     *
     * A domain doing both is asked both ways. They are separate routes at the
     * edge, and a rule can intercept one while leaving the other alone.
     *
     * @return array<int, string>
     */
    public function probeUrlsFor(Model $domain): array
    {
        $name = DomainName::make((string) $domain->fqdn);
        $requirements = $this->requirementsFor($domain);
        $path = (string) config('tenant-domains.reachability.path', '/');

        $urls = [];

        if ($requirements->root) {
            $urls[] = "https://{$name->value}{$path}";
        }

        if ($requirements->wildcard) {
            $label = Str::lower(Str::random(12));
            $urls[] = "https://{$label}.{$name->value}{$path}";
        }

        return $urls;
    }

    /* Certificate authorisation
     * - - - - - - - - - - - - - */

    /**
     * Override how the ask endpoint decides what may get a certificate.
     *
     * Bind this from a service provider when hostnames exist that have no row of
     * their own, such as per-account subdomains under a verified domain.
     *
     * @param  Closure(DomainName): bool  $callback
     */
    public static function authorizeCertificatesUsing(Closure $callback): void
    {
        self::$certificateAuthorization = $callback;
    }

    public static function forgetCertificateAuthorization(): void
    {
        self::$certificateAuthorization = null;
    }

    /**
     * Whether the edge may obtain a certificate for a hostname.
     *
     * Deny by default. The built-in rule authorises a hostname that is itself a
     * routable domain, and nothing else.
     */
    public function mayIssueCertificateFor(DomainName $host): bool
    {
        $allowed = self::$certificateAuthorization !== null
            ? (bool) (self::$certificateAuthorization)($host)
            : $this->isRoutableDomain($host);

        if (! $allowed) {
            // An attack and a setup bug look identical without a record of the ask.
            Log::info('[tenant-domains] Refused a certificate request', ['host' => $host->value]);
        }

        return $allowed;
    }

    private function isRoutableDomain(DomainName $host): bool
    {
        $table = (string) config('tenant-domains.table', 'domains');

        return DB::table($table)
            // Custom domains are stored whole, platform subdomains as a bare label.
            ->where(function ($query) use ($host) {
                $query->where(function ($query) use ($host) {
                    $query->where('domain', $host->value)->whereNull('platform_base');
                });

                if (! $host->isUnder($this->platform->domain) || $host->value === $this->platform->domain) {
                    return;
                }

                $label = substr($host->value, 0, -strlen('.'.$this->platform->domain));

                // One label only. Deep junk subdomains must not match a tenant's row.
                if ($label !== '' && ! str_contains($label, '.')) {
                    $query->orWhere(function ($query) use ($label) {
                        $query->where('domain', $label)->whereNotNull('platform_base');
                    });
                }
            })
            // Must stay nested: at the top level this `orWhereNotNull` would match
            // any row with a platform base, authorising every hostname on earth.
            ->where(function ($query) {
                $query->whereNotNull('platform_base')
                    ->orWhereIn('status', [
                        Enums\DomainStatus::Verified->value,
                        Enums\DomainStatus::Confirming->value,
                    ]);
            })
            ->exists();
    }

    /* Internals
     * - - - - - - - - - - - - - */

    public function requirementsFor(Model $domain): DomainRequirements
    {
        if (app()->bound(ResolvesDomainRequirements::class)) {
            return app(ResolvesDomainRequirements::class)->requirementsFor($domain);
        }

        return DomainRequirements::default();
    }

    /**
     * The tenant's ownership token, from the domain or from the tenant it
     * belongs to.
     *
     * The relation is named in config rather than guessed, because every app
     * calls its tenant something different and a wrong guess here fails at
     * verification time with a confusing message.
     */
    private function ownershipTokenFor(Model $domain): string
    {
        if ($domain instanceof ProvidesOwnershipToken) {
            return $domain->domainOwnershipToken();
        }

        $relation = (string) config('tenant-domains.tenant_relation', 'tenant');
        $tenant = $domain->isRelation($relation) ? $domain->getRelationValue($relation) : null;

        if ($tenant instanceof ProvidesOwnershipToken) {
            return $tenant->domainOwnershipToken();
        }

        throw MissingOwnershipToken::for($domain, $relation);
    }
}
