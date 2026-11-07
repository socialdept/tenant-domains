# Testing

Part of the [tenant-domains docs](introduction.md#documentation). Testing your app's domain lifecycle without DNS or an edge.

## Fakes

The package ships fakes for DNS and the edge. `ArrayResolver` answers lookups from an array written the way a person reads DNS, and `FakeIngressDriver` records what would have been published:

```php
use SocialDept\TenantDomains\Contracts\DnsResolver;
use SocialDept\TenantDomains\Contracts\IngressDriver;
use SocialDept\TenantDomains\Dns\ArrayResolver;
use SocialDept\TenantDomains\Testing\FakeIngressDriver;

$resolver = new ArrayResolver([
    'blog.example.com' => ['CNAME' => 'to.yourapp.com'],
    '_verify.blog.example.com' => ['TXT' => 'verification=tok_123'],
]);
$this->app->instance(DnsResolver::class, $resolver);

$ingress = new FakeIngressDriver();
$this->app->instance(IngressDriver::class, $ingress);

// ...

$ingress->assertPublished('blog.example.com', delegatedTo: 'k7m2q4bdhxwz.acme.yourapp.com');
```

A host missing from the array resolves to nothing, which is the ordinary "record not created yet" case. `$resolver->fail('blog.example.com')` makes lookups for that host throw `DnsUnavailable`, which simulates a resolver outage. That is a different thing from a missing record, and it must never be reported as the tenant's fault.

`FakeIngressDriver` also has `assertNotPublished()`, `assertWithdrawn()`, `assertNothingPublished()`, and `pretendIssued($host)` to make `certificateState()` report a certificate. Pass a list of `IngressCapability` cases to its constructor to test an edge that cannot issue wildcards.
