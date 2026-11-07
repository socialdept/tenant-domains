# Verification

Part of the [tenant-domains docs](../introduction.md#documentation). Proving ownership and routing, the reconciler that finishes setup, and the events it fires.

## The checks

A domain goes through four steps, and its `status` moves from `pending` to `confirming` to `verified`:

1. **Ownership.** The TXT record. TXT is never proxied, so this resolves whatever CDN the tenant uses, and it proves control without moving any traffic.
2. **Routing.** The CNAME or A record points at you.
3. **Publishing.** The domain is published to the edge, which gets a certificate for it, and its status becomes `confirming`.
4. **Reachability.** A real HTTPS request to the domain lands on your origin, and its status becomes `verified`.

Each check is available on its own:

```php
use SocialDept\TenantDomains\Facades\TenantDomains;

TenantDomains::verifyOwnership($domain);   // bool

$routing = TenantDomains::verifyRouting($domain);

$routing->verified;             // whether it points at you
$routing->method;               // 'cname' or 'a_record', whichever satisfied the check
$routing->resolved;             // what the name resolves to instead
$routing->hint;                 // a message for the tenant, when there is one
$routing->isProxied();          // the records sit behind a proxy, so their target is invisible
$routing->resolvesElsewhere();  // something answers at this name, but not you

TenantDomains::publish($domain);
TenantDomains::certificateState($domain);
```

Both verify methods throw `DnsUnavailable` when the resolver itself fails, so an outage is never reported as the tenant's missing record. A proxied result is not verified: DNS cannot see past the proxy, and only the reachability request can settle it.

## The reconciler

Tenants paste their records and close the tab. DNS propagates twenty minutes later. This is what finishes the job without them coming back:

```php
Schedule::command('domains:reconcile')->everyFiveMinutes();
```

Each pass checks every custom domain that is not verified, including failed ones, oldest check first, and carries each as far as its DNS allows. It is idempotent, so it is safe to run by hand or twice at once.

| Option | What it does |
|---|---|
| `--domain=` | Reconcile one domain by name, ignoring the throttle |
| `--dry-run` | Report what would change without writing anything |
| `--limit=` | Maximum domains to check in this pass |

`reconcile.batch` (100) is the default limit, and `reconcile.throttle` (60) skips domains checked that many seconds ago, so overlapping runs do not double up DNS lookups and edge writes. Set it to 0 to turn it off. The command fails when every lookup in a pass was unavailable, since that is your outage rather than every tenant failing at once.

## Reachability

DNS resolving is not the same as a request arriving. Once a domain is routed, one real HTTPS request is made to it and the response inspected. That is the only way to catch a redirect rule or worker in front of you that passes every DNS check and still breaks the domain.

```php
'reachability' => [
    'enabled' => true,
    'path' => '/',
    'timeout' => 8,
],
```

Redirects are never followed, because a redirect is the failure being hunted. A redirect back to the same host always counts as reaching you. If your app sends unverified hostnames somewhere else of its own, rebind `ReachabilityProbe` to a `ConfirmReachable` that claims those redirects, or the probe reads them as an interception and no domain ever finishes:

```php
use SocialDept\TenantDomains\Actions\ConfirmReachable;
use SocialDept\TenantDomains\Contracts\ReachabilityProbe;

$this->app->singleton(ReachabilityProbe::class, fn () => new ConfirmReachable(
    timeout: 8,
    acceptRedirectTo: fn (string $target, string $host): bool => $target === 'yourapp.com',
));
```

With `reachability.enabled` off, a domain is verified once its DNS is right, and a proxied domain is held back with a message asking the tenant to set it to DNS only during setup.

## Events

The reconciler fires each event once, on the transition, never once per pass:

| Event | When |
|---|---|
| `OwnershipProven` | The ownership record was found |
| `RoutingVerified` | The routing record points at you |
| `DomainConfirming` | The domain was published to the edge |
| `DomainVerified` | The domain is in service. `$event->method` is `request`, or `dns` when reachability is off |
| `DomainFailed` | A check failed for a new reason. `$event->reason` is the message for the tenant |

Every event carries the row as `$event->domain`, and `$event->hostname()` returns its hostname. `DomainFailed` fires only when the reason changes, so a domain that stays broken does not notify every five minutes.
