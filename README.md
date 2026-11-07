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

This package lets the tenants of a Laravel app bring their own domain. It proves they own it, tells them the exact records to create in the form their DNS provider expects, gets a certificate through an `_acme-challenge` delegation without touching their zone, tells your Caddy edge to serve it, and then makes a real request to prove traffic lands on your origin. To learn all about it, head over to [the documentation](https://socialde.pt/docs/tenant-domains).

Here are a few short examples of what you can do. Check what a tenant typed before storing anything:

```php
use SocialDept\TenantDomains\Facades\TenantDomains;

$check = TenantDomains::inspect('www.example.co.uk');

$check->domain;               // 'example.co.uk', the row to create
$check->www->supported;       // true, so ask the tenant about www
```

Get the records the tenant has to create:

```php
TenantDomains::ensureDelegationId($domain);

$instructions = TenantDomains::instructionsFor($domain);

$instructions->record('ownership');   // TXT    _verify.blog          verification=…
$instructions->record('acme');        // CNAME  _acme-challenge.blog  k7m2q4bdhxwz.acme.yourapp.com
```

Verify the domain and hand it to the edge:

```php
if (TenantDomains::verifyOwnership($domain) && TenantDomains::verifyRouting($domain)->verified) {
    TenantDomains::publish($domain);
}
```

Or let the reconciler finish setup once the tenant's DNS has propagated:

```php
Schedule::command('domains:reconcile')->everyFiveMinutes();
```

## Documentation

You'll find the documentation on [https://socialde.pt/docs/tenant-domains](https://socialde.pt/docs/tenant-domains).

Find yourself stuck using the package? Found a bug? Do you have general questions or suggestions for improving it? Feel free to [create an issue on GitHub](https://github.com/socialdept/tenant-domains/issues).

## Testing

```bash
vendor/bin/phpunit
```

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## License

The MIT License (MIT). Please see [LICENSE](LICENSE) for more information.
