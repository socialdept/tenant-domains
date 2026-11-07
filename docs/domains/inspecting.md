# Inspecting a hostname

Part of the [tenant-domains docs](../introduction.md#documentation). Checking what a tenant typed, offline, before any row exists.

## Inspecting

One call answers everything structural about what a tenant typed:

```php
use SocialDept\TenantDomains\Facades\TenantDomains;

$check = TenantDomains::inspect('www.example.co.uk');

$check->host;                    // 'www.example.co.uk', lowercased and trimmed
$check->domain;                  // 'example.co.uk', the row to create
$check->valid->ok;               // true
$check->valid->error;            // null, or why it was refused
$check->apex->isApex;            // false (www.example.co.uk is not itself an apex)
$check->apex->registrableDomain; // 'example.co.uk', the zone the tenant edits
$check->apex->recordPrefix;      // 'www', null at the apex, 'blog' for blog.example.com
$check->www->isWww;              // true
$check->www->host;               // 'www.example.co.uk'
$check->www->supported;          // true, so the www question applies
$check->www->defaultRedirect;    // WwwRedirect::ToWww
$check->isPlatformHost;          // true for your own domain and anything under it
```

A typed `www.` host is stored as its apex, so `domain` is `example.co.uk` here. The two cannot sensibly be separate rows, because one of them has to own the certificate and decide which way the redirect runs. Anything else is kept as typed, including `www.blog.example.com`, which is not an alias of anything. The preselected redirect is `to_www` when the tenant typed the `www.` host and `from_www` when they typed the apex. [Serving www](www.md) covers what those mean.

It is **offline**: the [Public Suffix List](https://publicsuffix.org/) and string rules, no DNS and no database. That is what makes it safe to call on every keystroke.

## From a form

Expose it from a route and your front end never has to work any of this out again:

```php
Route::get('/domains/inspect', fn (Request $request) => TenantDomains::inspect(
    (string) $request->query('domain'),
));
```

The result serializes to JSON in snake case, grouped the same way: `host`, `domain`, `valid` (`ok`, `error`), `apex` (`is_apex`, `registrable_domain`, `record_prefix`), `www` (`is_www`, `host`, `supported`, `default_redirect`) and `is_platform_host`.

> [!TIP]
> Do this rather than shipping a suffix table to the client. A short list of suffixes in JavaScript disagrees with the server for every registrable suffix outside it, so a tenant on `.com.ng` is never asked the www question the server would have honored.

## What it does not answer

These questions are not offline, so they live elsewhere:

| Question | Where |
|---|---|
| Which routing record to recommend | `recommendRoutingMode()`, which reads nameservers |
| Is this domain already taken | `ValidCustomDomain`, which knows your table |
| Does its DNS point at us | `verifyRouting()` |

## Validating on submit

`ValidCustomDomain` takes its structural verdicts from `inspect()`, so a form that checks while the tenant types cannot disagree with the rule that runs on submit. It also refuses a domain already in the table, and takes the id of the row being edited so it does not refuse its own:

```php
use SocialDept\TenantDomains\Rules\ValidCustomDomain;

$request->validate([
    'domain' => ['required', 'string', new ValidCustomDomain($domain?->id)],
]);
```

The platform's own domain and anything under it are refused, because those are yours to hand out as subdomains.
