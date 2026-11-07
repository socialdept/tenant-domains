# Serving www

Part of the [tenant-domains docs](../introduction.md#documentation). Letting an apex answer on its `www.` host, and which of the two is the address.

## Choosing

An apex domain can also answer on its `www.` host, and the tenant chooses which of the two visitors end up at. Set `www_redirect` on the domain row:

| Value | `example.com` | `www.example.com` |
|---|---|---|
| `null` *(default)* | served | not served |
| `none` | served | not served |
| `from_www` | served | redirects to the apex |
| `to_www` | redirects to `www.` | served, and is the address |

The column is cast to the `WwwRedirect` enum (`FromWww`, `ToWww`, `None`). [Inspecting a hostname](inspecting.md) tells you whether to ask at all (`$check->www->supported`) and what to preselect.

Opting in is what makes the package serve the host, so it does three things at once: the instructions gain a `www` CNAME, the edge binding gains `www.example.com` as a certificate subject, and the certificate authority endpoint starts authorizing it.

> [!NOTE]
> It is deliberately off by default. Every apex on the internet has a `www.` that resolves somewhere, and certifying them all unasked spends a platform's [Let's Encrypt rate limits](https://letsencrypt.org/docs/rate-limits/) on hostnames nobody visits.

## Hostname and address

Two attributes on the row, and they are not the same thing:

```php
$domain->hostname;  // example.com      the name, and where DNS records hang off
$domain->address;   // www.example.com  where a visitor should end up
```

`hostname` never moves. Every record the package asks a tenant to create is computed relative to it, so the ownership TXT belongs at `_verify.example.com` and the apex A record at `@` no matter which of the two hosts visitors are sent to. Build public URLs from `address` instead, or every link 301s on the way to the page.

Both are fully qualified, which is why neither is called `fqdn`: that says what they are and not which one you want.

## Reachability

When `to_www` makes the `www.` host the address, the reachability probe requests **both** hosts: the apex, which issues the redirect, and the `www.` host, which serves the page. Nothing else checks the `www.` record, so without that a tenant could choose `to_www`, never create it, verify green on the apex alone, and have every reader redirected to a host that does not resolve.

## Redirecting and routing requests

The package does not redirect. It tells you which host is canonical, and serving the redirect is your app's middleware, which already knows how to send a visitor to a tenant's primary domain.

To resolve an incoming request to a row, including a `www.` host that has no row of its own:

```php
// Rows that answer for this host: the exact one, plus an apex serving it as its alias.
Domain::query()->servingHost($request->getHost())->get();

// The same, with the exact row ordered first, which is the one that must win.
Domain::query()->servingHostByPrecedence($request->getHost())->first();
```

Only an apex gets an alias. `www.blog.example.com` never matches a `blog.example.com` row, and a platform subdomain never carries one. Both scopes match custom domains only, since platform subdomains are stored as a bare label.
