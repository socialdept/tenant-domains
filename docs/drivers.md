# Drivers

Part of the [tenant-domains docs](introduction.md#documentation). The zone and ingress drivers that ship with the package, their settings, and registering your own.

## Choosing drivers

```php
'drivers' => ['zone' => 'cloudflare', 'ingress' => 'caddy'],
```

Both default to `null`, set by `TENANT_DOMAINS_ZONE_DRIVER` and `TENANT_DOMAINS_INGRESS_DRIVER`.

**Zone.** `cloudflare`, `manual` (logs what to create by hand), `null`. A zone driver manages records in *your* zone, the platform domain, never the tenant's. The package binds the configured one as `ZoneDriver` for your app to resolve, with `upsert()`, `delete()`, `find()` and `configured()`.

**Ingress.** `caddy`, `null` (for a statically configured edge with one origin and on-demand certificates, where publishing is a no-op). The configured one is bound as `IngressDriver`, and `publish()`, `withdraw()` and `certificateState()` go through it.

## Cloudflare

```dotenv
CLOUDFLARE_API_TOKEN=
CLOUDFLARE_ZONE_ID=
```

Records are matched by type and name, so no Cloudflare record ids are stored.

## Caddy

The Caddy driver programs Caddy's TLS app through the admin API. In `delegated_dns` mode it writes one automation policy per custom domain, as [Certificate modes](certificates/modes.md) explains. In `on_demand` mode there is nothing to program and the [certificate authority endpoint](certificates/authority-endpoint.md) is the only gate.

| Setting | Env | Default |
|---|---|---|
| `admin_url` | `CADDY_ADMIN_URL` | `http://localhost:2019` |
| `dns_provider` | `CADDY_DNS_PROVIDER` | `cloudflare` |
| `dns_api_token` | `CADDY_DNS_TOKEN_PLACEHOLDER` | `{env.CF_API_TOKEN}` |
| `resolvers` | | `['1.1.1.1', '8.8.8.8']` |
| `propagation_delay` | | `10s` |
| `propagation_timeout` | | `300s` |

These live under `drivers.ingresses.caddy`. `dns_api_token` is written into Caddy's config as a Caddy env placeholder rather than the secret itself, because the admin API config is readable. Set the real token in Caddy's own environment.

Publishing a binding that is already published sends no write, so the reconciler can run every few minutes without churning the edge. A domain with no delegation target is skipped and logged rather than added to a shared policy that would fail every issuance.

## Your own driver

Register a driver from a service provider:

```php
use SocialDept\TenantDomains\Drivers\DriverManager;

app(DriverManager::class)->extendIngress('traefik', fn ($app) => new TraefikDriver(...));
```

`extendZone()` does the same for zone drivers. An ingress driver implements `IngressDriver`, including `supports(IngressCapability $capability)`, which has to answer truthfully so a domain that needs a wildcard fails at `publish()` instead of never getting a certificate. A zone driver implements `ZoneDriver`.
