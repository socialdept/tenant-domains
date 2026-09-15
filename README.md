<h3 align="center">
    Custom domains for multi-tenant Laravel apps. Cloudflare for SaaS, without Cloudflare for SaaS.
</h3>

<p align="center">
    <br>
    <a href="https://packagist.org/packages/socialdept/tenant-domains" title="Latest Version on Packagist"><img src="https://img.shields.io/packagist/v/socialdept/tenant-domains.svg?style=flat-square"></a>
    <a href="https://packagist.org/packages/socialdept/tenant-domains" title="Total Downloads"><img src="https://img.shields.io/packagist/dt/socialdept/tenant-domains.svg?style=flat-square"></a>
    <a href="https://github.com/socialdept/tenant-domains/actions/workflows/tests.yml" title="GitHub Tests Action Status"><img src="https://img.shields.io/github/actions/workflow/status/socialdept/tenant-domains/tests.yml?branch=main&label=tests&style=flat-square"></a>
    <a href="LICENSE" title="Software License"><img src="https://img.shields.io/github/license/socialdept/tenant-domains?style=flat-square"></a>
</p>

---

## What is Tenant Domains?

**Tenant Domains** lets your tenants bring their own domain. It proves they own it, tells
them the exact records to create in the words their DNS provider uses, gets a certificate
without ever touching their zone, makes your edge serve it, and then proves a real request
actually lands on the right origin.

Think of it as Cloudflare for SaaS, the Custom Hostnames product, running on your own Caddy
for the price of a DNS zone you already have.

## Why use Tenant Domains?

- **Ownership without downtime.** A TXT record proves control while the tenant's existing
  site and email keep serving
- **Instructions that paste correctly.** Record names are zone relative (`@`,
  `_acme-challenge.blog`), because every provider appends the zone itself
- **Apex done properly.** Apex detection comes from the Public Suffix List, so
  `example.co.uk` is a root and gets an A record instead of a CNAME its registrar refuses
- **Certificates through delegation.** DNS-01 solved in *your* zone via the tenant's
  `_acme-challenge` CNAME, so their CDN, proxy or firewall is irrelevant
- **Wildcards.** For per-account hostnames like `alice.tenant.com`
- **Proof, not inference.** A real request confirms the domain reaches you, catching the
  redirect rule that passes every DNS check and still breaks the domain
- **Honest failures.** A resolver outage is never reported as the tenant's missing record
- **Driver based.** Cloudflare and Caddy ship in the box. Anything else is two classes

## Quick Example

```php
use SocialDept\TenantDomains\Facades\TenantDomains;

$domain = $publication->domains()->create(['domain' => 'blog.example.com']);

TenantDomains::ensureDelegationId($domain);

// Everything the tenant has to create, ready to render.
$instructions = TenantDomains::instructionsFor($domain)->toArray();

// ['type' => 'CNAME', 'name' => 'blog',                'value' => 'to.yourapp.com']
// ['type' => 'TXT',   'name' => '_verify.blog',        'value' => 'verification=…']
// ['type' => 'CNAME', 'name' => '_acme-challenge.blog','value' => 'k7m2q9.acme.yourapp.com']

if (TenantDomains::verifyOwnership($domain) && TenantDomains::verifyRouting($domain)->verified) {
    TenantDomains::publish($domain);   // the edge now serves it and gets a certificate
}
```

## Installation

```bash
composer require socialdept/tenant-domains
```

```bash
php artisan vendor:publish --tag=tenant-domains-config

# A fresh domains table...
php artisan vendor:publish --tag=tenant-domains-migrations

# ...or, if you already have one (stancl/tenancy does), just the extra columns:
php artisan vendor:publish --tag=tenant-domains-migrations-columns

php artisan migrate
```

Point the package at your platform:

```dotenv
TENANT_DOMAINS_PLATFORM=yourapp.com
TENANT_DOMAINS_INGRESS_IPS=203.0.113.10
TENANT_DOMAINS_CERT_MODE=delegated_dns
TENANT_DOMAINS_INGRESS_DRIVER=caddy
```

## Naming the records

Every hostname the package hands a tenant is configurable. The defaults are a convention,
not a requirement, and nothing in the code assumes them.

| Setting | Default | What it is |
|---|---|---|
| `routing.cname_target` | `to.{platform}` | Where tenant subdomains CNAME to |
| `routing.ingress_ips` | none | Addresses an apex A record may use |
| `certificates.delegation_suffix` | `acme.{platform}` | Zone that answers DNS-01 challenges |
| `ownership.prefix` | `_verify` | Label of the ownership TXT record |
| `ownership.value_prefix` | `verification=` | What that record's value starts with |

`{platform}` expands to your platform domain, and it is optional. Drop it and the value is
used verbatim, so the target can be anything that resolves:

```php
// A different label under your own domain
'cname_target' => 'ingress.{platform}',

// An existing load balancer on a completely separate domain
'cname_target' => 'lb-7a2.eu-west.example.net',

// A CDN hostname you already publish
'cname_target' => 'customers.yourcdn.example',
```

The delegation suffix is independent too, so challenges can be answered by whichever zone
you can actually write to rather than a subdomain of the platform.

If you are adopting this over an existing implementation, set these to match the records
your tenants have already created. Changing them on a live platform invalidates every
domain already verified.

With the defaults, that means creating one record in your own zone: `to.yourapp.com`,
pointing at your ingress.

## Getting Started

### 1. Tell the package who owns a domain

Your tenant model supplies the token that ends up in the ownership TXT record. It has to be
stable for the life of the tenant, because rotating it invalidates every domain already
verified against it. It also must not be guessable from the tenant's public identity.

```php
use SocialDept\TenantDomains\Contracts\ProvidesOwnershipToken;

class Publication extends Model implements ProvidesOwnershipToken
{
    public function domainOwnershipToken(): string
    {
        return $this->domain_token;
    }
}
```

### 2. Mix the trait into your domain model

```php
use SocialDept\TenantDomains\Models\Concerns\IsCustomDomain;
use Stancl\Tenancy\Database\Models\Domain as BaseDomain;

class Domain extends BaseDomain
{
    use IsCustomDomain;

    protected function casts(): array
    {
        return [...parent::casts(), ...$this->customDomainCasts()];
    }
}
```

No stancl? Extend `SocialDept\TenantDomains\Models\Domain` instead.

### 3. Point your edge at the certificate authority endpoint

Your edge asks this before obtaining a certificate for a hostname it does not recognise.
The package registers it at `api/caddy/verify`, and the path is yours to change:

```php
'authority_endpoint' => [
    'path' => 'internal/tls/authorize',
    'middleware' => ['internal-only'],
],
```

Prefer to own the route entirely? Turn registration off and declare it wherever your API
conventions put it:

```php
// config/tenant-domains.php
'authority_endpoint' => ['enabled' => false],

// routes/api.php
Route::get('v2/tls/authorize', CertificateAuthorityController::class);
```

Whichever path you choose has to match your edge config. In Caddy that is the `ask` value
in the `on_demand_tls` block. Keep the endpoint unauthenticated but unreachable from
outside, because the edge calls it over loopback and a public one leaks which domains you
host.

It **denies by default**. Anything your app does not explicitly claim gets a 404. If you
serve per-account hostnames that have no row of their own, widen it:

```php
Domains::authorizeCertificatesUsing(
    fn (DomainName $host) => Domain::where('domain', $host->parent())->verified()->exists(),
);
```

### 4. Schedule the reconciler

Tenants paste their records and close the tab. DNS propagates twenty minutes later. This is
what finishes the job without them coming back.

```php
Schedule::command('domains:reconcile')->everyFiveMinutes();
```

## Certificate modes

The single most consequential setting. It decides what your platform can offer.

| | `delegated_dns` | `on_demand` |
|---|---|---|
| Challenge | DNS-01 in your zone | HTTP-01 / TLS-ALPN |
| Needs | admin API plus a zone you control | nothing |
| **Wildcards** | **yes** | **no.** Neither challenge can prove one |
| Tenant behind a CDN | fine | fragile |
| Per-domain edge config | one policy each | none |

`delegated_dns` is the default. Choose `on_demand` only if every custom domain shares one
origin and you will never need a wildcard. The package refuses a wildcard binding outright
rather than letting it silently never get a certificate.

### How the delegation works

CertMagic does **not** follow the tenant's `_acme-challenge` CNAME. It resolves the
challenge name to its SOA and asks your DNS provider for that zone, which you do not own,
then fails with `expected 1 zone, got 0`. Silently, after setup already reported success.

The fix is `override_domain`, which this package writes into a per-domain automation policy.
It replaces the record name outright so the TXT lands at the delegation target inside your
zone. Let's Encrypt does follow CNAMEs when validating, so it chases the tenant's record to
the same place and reads it there.

The override is per solver, so it is per policy, so it is one policy per custom domain.

## Drivers

```php
'drivers' => ['zone' => 'cloudflare', 'ingress' => 'caddy'],
```

**Zone.** `cloudflare`, `manual` (logs what to create by hand), `null`.
**Ingress.** `caddy`, `null` (for a statically configured edge).

Register your own from a service provider:

```php
app(DriverManager::class)->extendIngress('traefik', fn ($app) => new TraefikDriver(...));
```

## Testing

```bash
composer test
```

The package ships fakes so your app's domain lifecycle is testable without DNS or an edge:

```php
$resolver = new ArrayResolver([
    'blog.example.com' => ['CNAME' => 'to.yourapp.com'],
    '_verify.blog.example.com' => ['TXT' => 'verification=tok_123'],
]);

$ingress = new FakeIngressDriver();
$this->app->instance(IngressDriver::class, $ingress);

// ...

$ingress->assertPublished('blog.example.com', delegatedTo: 'k7m2q9.acme.yourapp.com');
```

`ArrayResolver::fail()` simulates a resolver outage. That is a different thing from a
missing record, and it must never be reported as the tenant's fault.

## Requirements

- PHP 8.3+
- Laravel 11, 12 or 13
- `jeremykendall/php-domain-parser` ^6 for Public Suffix List parsing
- `stancl/tenancy` ^3 (optional) for the packaged domain model

## Resources

- [Public Suffix List](https://publicsuffix.org/) decides apex vs subdomain
- [Caddy TLS automation](https://caddyserver.com/docs/json/apps/tls/) is the config this writes
- [RFC 8555 §8.4](https://datatracker.ietf.org/doc/html/rfc8555) covers DNS-01 challenges
- [Let's Encrypt rate limits](https://letsencrypt.org/docs/rate-limits/) explain why the ask endpoint denies by default

## Support & Contributing

Found a bug or have a feature request? [Open an issue](https://github.com/socialdept/tenant-domains/issues).

Want to contribute? Check out the [contribution guidelines](CONTRIBUTING.md).

## Credits

- [Miguel Batres](https://batres.co) - founder & lead maintainer

## License

Tenant Domains is open-source software licensed under the [MIT license](LICENSE).

---

**Built for Multi-Tenant Laravel** • By Social Dept.
