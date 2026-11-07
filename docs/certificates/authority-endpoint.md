# Certificate authority endpoint

Part of the [tenant-domains docs](../introduction.md#documentation). The endpoint your edge asks before it gets a certificate for a hostname.

## The route

Your edge asks this endpoint before obtaining a certificate for a hostname it does not recognize. It sends the hostname as the `domain` query parameter and gets a 200 to go ahead, a 404 to refuse, or a 400 for a missing or invalid hostname.

The package registers it at `api/caddy/verify`, named `tenant-domains.authority`, and the path is yours to change:

```php
'authority_endpoint' => [
    'path' => 'internal/tls/authorize',
    'middleware' => ['internal-only'],
],
```

Prefer to own the route entirely? Turn registration off and declare it wherever your API conventions put it:

```php
// config/tenant-domains.php
'authority_endpoint' => ['enabled' => false],

// routes/api.php
use SocialDept\TenantDomains\Http\CertificateAuthorityController;

Route::get('v2/tls/authorize', CertificateAuthorityController::class);
```

Whichever path you choose has to match your edge config. In Caddy that is the `ask` value in the `on_demand_tls` block.

> [!IMPORTANT]
> Keep the endpoint unauthenticated but unreachable from outside. The edge calls it over loopback, and a public one leaks which domains you host.

## What it authorizes

It **denies by default**. Anything your app does not explicitly claim gets a 404, and each refusal is logged, so a legitimate miss can be diagnosed. The built-in rule authorizes:

- a custom domain whose status is `confirming` or `verified`,
- the `www.` host of such an apex, when its `www_redirect` is `from_www` or `to_www`,
- a single label under your platform domain that has a platform subdomain row.

The default is strict because, in on-demand mode, this endpoint is all that stands between a port scanner and the [Let's Encrypt rate limits](https://letsencrypt.org/docs/rate-limits/) for your registered domain.

If you serve per-account hostnames that have no row of their own, widen it from a service provider:

```php
use SocialDept\TenantDomains\Core\DomainName;
use SocialDept\TenantDomains\Domains;
use SocialDept\TenantDomains\Enums\DomainStatus;

Domains::authorizeCertificatesUsing(
    fn (DomainName $host): bool => Domain::query()
        ->custom()
        ->whereIn('domain', array_filter([$host->value, $host->parent()?->value]))
        ->withStatus(DomainStatus::Confirming, DomainStatus::Verified)
        ->exists(),
);
```

The callback replaces the built-in rule rather than adding to it, so it has to cover every hostname you serve, the domains themselves included. This one authorizes a custom domain and any single label under it, but not `www.` aliases or platform subdomains.
