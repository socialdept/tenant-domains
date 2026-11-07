# DNS records

Part of the [tenant-domains docs](../introduction.md#documentation). The records a tenant is asked to create, and how to name them.

## Instructions

`instructionsFor()` returns every record the tenant has to create, with the context a UI needs to explain them. Give the domain a delegation id first, so the `_acme-challenge` record can be included:

```php
use SocialDept\TenantDomains\Facades\TenantDomains;

TenantDomains::ensureDelegationId($domain);

$instructions = TenantDomains::instructionsFor($domain);

$instructions->record('root');        // CNAME  blog                  to.yourapp.com
$instructions->record('ownership');   // TXT    _verify.blog          verification=…
$instructions->record('acme');        // CNAME  _acme-challenge.blog  k7m2q4bdhxwz.acme.yourapp.com
```

Records are keyed by purpose: `root`, `www`, `wildcard`, `ownership` and `acme`. Each is a `DnsRecord` with a `type`, a zone relative `name` for the provider's Name field, the fully qualified `host` for looking it up, and a `value`. `$instructions->toArray()` returns the same, ready to render, along with `isApex`, `routingMode`, `routingModeIsChoosable`, `wwwRedirect`, `wwwIsChoosable`, `address`, `requirements` and what was detected about the tenant's DNS provider (`providerName`, `isCloudflare`, `supportsApexCname`).

`ensureDelegationId()` never overwrites an id the domain already has. The tenant may already have `_acme-challenge` pointed at it, and a new id silently breaks their DNS. In `on_demand` certificate mode it returns null and no `acme` record is issued, because asking for a record nothing reads is worse than not asking.

## Routing records

A subdomain always takes a CNAME to the CNAME target. An apex can take a CNAME where the provider flattens it, or an A record to the first of `routing.ingress_ips`. Every address in that list is accepted when verifying, so list your origin and any passthrough edge in front of it.

The domain's `routing_mode` column (`cname` or `a_record`, `cname` by default in the migrations) decides which is shown, and the instructions fall back to a recommendation only when it is null. `recommendRoutingMode()` makes that recommendation from the domain's nameservers, so you can use it to preselect the choice: subdomains always get a CNAME, an apex gets a CNAME only on a provider known to flatten one, and an apex on a provider it does not recognize gets an A record, because that works everywhere.

## Naming the records

Every hostname the package hands a tenant is configurable. The defaults are a convention, not a requirement, and nothing in the code assumes them.

| Setting | Default | What it is |
|---|---|---|
| `routing.cname_target` | `to.{platform}` | Where tenant subdomains CNAME to |
| `routing.ingress_ips` | none | Addresses an apex A record may use |
| `certificates.delegation_suffix` | `acme.{platform}` | Zone that answers DNS-01 challenges |
| `ownership.prefix` | `_verify` | Label of the ownership TXT record |
| `ownership.value_prefix` | `verification=` | What that record's value starts with |

`{platform}` expands to your platform domain, and it is optional. Drop it and the value is used verbatim, so the target can be anything that resolves:

```php
// A different label under your own domain
'cname_target' => 'ingress.{platform}',

// An existing load balancer on a completely separate domain
'cname_target' => 'lb-7a2.eu-west.example.net',

// A CDN hostname you already publish
'cname_target' => 'customers.yourcdn.example',
```

The delegation suffix is independent too, so challenges can be answered by whichever zone you can actually write to rather than a subdomain of the platform.

> [!WARNING]
> If you are adopting this over an existing implementation, set these to match the records your tenants have already created. Changing them on a live platform invalidates every domain already verified.

With the defaults, that means creating one record in your own zone: `to.yourapp.com`, pointing at your ingress.

## What a domain is for

By default every domain needs a root record and no wildcard. When domains do different jobs, such as one serving the app and another only carrying per-account hostnames like `alice.tenant.com`, bind a `ResolvesDomainRequirements` implementation and return a `DomainRequirements` for each domain:

```php
use SocialDept\TenantDomains\Contracts\ResolvesDomainRequirements;
use SocialDept\TenantDomains\Data\DomainRequirements;

$this->app->bind(ResolvesDomainRequirements::class, DomainRequirementsResolver::class);

// in DomainRequirementsResolver
public function requirementsFor(Model $domain): DomainRequirements
{
    return new DomainRequirements(root: true, wildcard: true);
}
```

A wildcard adds a `*` CNAME to the instructions and a wildcard subject to the certificate. It needs `delegated_dns`, see [Certificate modes](../certificates/modes.md). `DomainRequirements::none()` keeps a name reserved with nothing to verify, route or certify.
