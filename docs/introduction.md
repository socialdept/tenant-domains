# Introduction

**Tenant Domains** lets the tenants of a multi-tenant Laravel app bring their own domain. It proves they own it, tells them the exact records to create in the form their DNS provider expects, gets a certificate without ever touching their zone, makes your edge serve it, and then proves a real request lands on the right origin. It does the job of Cloudflare for SaaS's Custom Hostnames product, on your own Caddy and a DNS zone you already have.

- **Ownership without downtime:** a TXT record proves control while the tenant's existing site and email keep serving.
- **Instructions that paste correctly:** record names are zone relative (`@`, `_acme-challenge.blog`), because every provider appends the zone itself.
- **Apex done properly:** apex detection comes from the Public Suffix List, so `example.co.uk` is a root and gets an A record instead of a CNAME its registrar refuses.
- **Certificates through delegation:** DNS-01 is solved in *your* zone through the tenant's `_acme-challenge` CNAME, so their CDN, proxy or firewall does not get in the way.
- **Wildcards:** for per-account hostnames like `alice.tenant.com`.
- **Proof, not inference:** a real request confirms the domain reaches you, catching the redirect rule that passes every DNS check and still breaks the domain.
- **Honest failures:** a resolver outage is never reported as the tenant's missing record.
- **Drivers:** Cloudflare for your own zone and Caddy for the edge ship with the package, and you can register your own.

## A first look

Create a domain row, give it a delegation id, and render the records the tenant has to create:

```php
use SocialDept\TenantDomains\Facades\TenantDomains;

$domain = $publication->domains()->create(['domain' => 'blog.example.com']);

TenantDomains::ensureDelegationId($domain);

$instructions = TenantDomains::instructionsFor($domain);

$instructions->record('root');        // CNAME  blog                  to.yourapp.com
$instructions->record('ownership');   // TXT    _verify.blog          verification=…
$instructions->record('acme');        // CNAME  _acme-challenge.blog  k7m2q4bdhxwz.acme.yourapp.com
```

Once the records exist, verify them and tell the edge to serve the domain and get it a certificate:

```php
if (TenantDomains::verifyOwnership($domain) && TenantDomains::verifyRouting($domain)->verified) {
    TenantDomains::publish($domain);
}
```

Tenants paste their records and close the tab, so a scheduled command carries every unfinished domain the rest of the way:

```php
Schedule::command('domains:reconcile')->everyFiveMinutes();
```

## Documentation

Start with [Installation](getting-started/installation.md), then [Domain models](getting-started/models.md) to connect the package to your tenants. The docs are grouped by area, and each group has an index of its own:

- **[Getting started](getting-started/README.md)**: [Installation](getting-started/installation.md), [Domain models](getting-started/models.md)
- **[Domains](domains/README.md)**: [Inspecting a hostname](domains/inspecting.md), [DNS records](domains/records.md), [Serving www](domains/www.md), [Verification](domains/verification.md)
- **[Certificates](certificates/README.md)**: [Certificate modes](certificates/modes.md), [Certificate authority endpoint](certificates/authority-endpoint.md)
- **[Drivers](drivers.md)**: The zone and ingress drivers that ship with the package, their settings, and registering your own.
- **[Testing](testing.md)**: Testing your app's domain lifecycle without DNS or an edge.
