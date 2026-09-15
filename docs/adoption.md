# Adoption: what to import, what to leave

Written for the two apps this was extracted from, and useful for any app replacing a
hand-rolled custom-domain implementation.

The short version: **import the DNS, detection, instruction and certificate logic. Keep
everything that knows what your product is.**

---

## What the package takes over

Delete your versions of these. They are the parts that were identical between both source
apps, or that one of them got right and the other did not.

| Yours | Package |
|---|---|
| DNS provider table (NS suffix to provider) | `Core\Detection\ProviderDetector::PROVIDERS` |
| Cloudflare IP ranges | `Core\Detection\ProxyDetector::CLOUDFLARE_RANGES` |
| `detectApexRouting()` | `ProviderDetector::recommendRoutingMode()` |
| Hand-maintained two-level suffix list | `Core\PublicSuffixList`. **A behaviour fix, see below** |
| `DomainRoutingMode` enum | `Enums\RoutingMode` |
| `DomainStatus` enum | `Enums\DomainStatus`, which gains `Confirming` |
| `instructions()` / `DnsInstructions::for()` | `Data\Instructions::build()` |
| `checkCname()` / `checkTxt()` / `checkApexARecord()` | `Actions\VerifyRouting`, `Actions\VerifyOwnership` |
| `isCloudflareProxied()` | `ProxyDetector::isProxied()` |
| ACME delegation id generation | `Core\DelegationId` |
| Caddy TLS automation policies | `Drivers\Ingress\CaddyIngressDriver` |
| Cloudflare DNS client | `Drivers\Zone\CloudflareZoneDriver` |
| The on-demand TLS `ask` endpoint | `Http\CertificateAuthorityController` |
| `ValidDomain` rule | `Rules\ValidCustomDomain` |
| Reachability probing | `Actions\ConfirmReachable` |
| The scheduled verify sweep | `domains:reconcile` |

## What stays yours

None of this belongs in a package. Trying to generalise it is how a shared library becomes
a liability.

| Stays | Why |
|---|---|
| **Plan limits and allowances** | Counting domains is trivial. The *policy* is product judgement: grace periods, what a downgrade does, which domains survive it |
| **Notifications and mail** | Listen for `DomainVerified` and send whatever you send |
| **Anything after verification** | Container rebuilds, identity migrations, cache warming. Listeners, not package concerns |
| **Domain reserve and release** | Generic looking, but only one app needs it |
| **Primary-domain redirects, path tenancy** | Tenancy routing, not custom-domain handling |
| **Cross-domain sessions** | A separate problem, and a separate package |
| **Your UI** | Deliberately not shipped. Render `Instructions::toArray()` however your design system wants |

## The three behaviour changes to plan for

Everything else is a like-for-like swap. These are not.

### 1. Apex detection changes for existing domains

A hand-maintained suffix list gets most domains right and a long tail wrong. Swapping to the
Public Suffix List **changes the apex verdict for some domains you already have**, and the
apex verdict decides whether a tenant is told to create a CNAME or an A record.

Audit before deploying:

```php
foreach (Domain::custom()->get() as $domain) {
    $old = $yourOldApexCheck($domain->domain);
    $new = DomainName::make($domain->domain)->isApex();

    if ($old !== $new) {
        // A verified domain must not be re-recommended into a different record.
        logger()->warning("Apex verdict changes for {$domain->domain}: {$old} -> {$new}");
    }
}
```

A domain already verified should keep its stored `routing_mode` regardless. The
recommendation is only ever consulted when a domain is first added.

### 2. Ownership record values must be preserved exactly

Whatever your existing TXT records say in the wild is what the package has to keep checking.
Set both halves to match:

```php
'ownership' => [
    'prefix' => '_offprint',        // your existing record name
    'value_prefix' => 'aturi=',     // your existing value prefix
],
```

Get this wrong and every verified domain fails its next re-check.

### 3. `acme_delegation_id` values must survive untouched

`ensureDelegationId()` never overwrites, and there is a test pinning that. The migration must
not overwrite either. A tenant already has `_acme-challenge` pointed at the existing id.
Re-minting silently invalidates their DNS, so no certificate issues and every hostname under
the domain goes dark at the next renewal.

## Migrating in two steps

Do not change the certificate mode in the same deploy as the port.

**Step 1: port, behaviour-identical.** Set `certificates.mode` to whatever your edge does
today. If your Caddyfile has no `override_domain` anywhere, that is `on_demand`, with the
`null` ingress driver. Nothing about certificates changes. You are only replacing the DNS and
instruction logic, and your existing test suite is the proof.

**Step 2: switch to `delegated_dns`, separately and revertably.** If you already collect
delegation ids and already ask tenants for the `_acme-challenge` CNAME, this asks nothing new
of anyone. It starts using a record that has been sitting there inert. Keep an on-demand
catch-all behind it so a domain whose delegation is not right yet still gets a certificate
the old way rather than none.

## The columns you are adding

`tenant-domains-migrations-columns` adds only what is missing, so it is safe against a table
that already carries some of them.

The one worth understanding is **`platform_base`**. Both source apps decide "custom domain or
platform subdomain?" with `str_contains($domain, '.')`. That works only while there is
exactly one platform base domain. Offer a second and every subdomain under it has dots and is
misfiled as a custom domain, in `fqdn`, in certificate targets, and in every
`whereRaw("INSTR(domain, '.')")` in the codebase.

`platform_base` makes it explicit. Non-null means a platform subdomain and `domain` holds the
bare label. Null means a custom domain and `domain` holds the FQDN. The migration backfills
existing rows, so nothing falls back to the heuristic even once.

## The reconcile loop

`domains:reconcile` carries each unfinished domain one step further per pass.

| Pass | Checks | Result |
|---|---|---|
| 1 | Ownership TXT | `ownership_verified_at` stamped, `OwnershipProven` fired |
| 2 | Routing record | Published to the edge, status becomes `Confirming`, `DomainConfirming` fired |
| 3 | A real HTTPS request | Status becomes `Verified`, `DomainVerified` fired |

Three things about it are deliberate and worth knowing before you change it.

**It stops after routing.** The certificate has only just been requested, so probing over
HTTPS in the same pass would fail for a reason that has nothing to do with the tenant.

**`DnsUnavailable` is never a failure.** A resolver blip would otherwise mark a whole table
as failing in one pass. Those domains come back untouched, with no event fired.

**Failure events fire on change, not per pass.** Running every five minutes, an event per
pass is an event forever. One broken record must not become a notification every five
minutes for as long as it stays broken.
