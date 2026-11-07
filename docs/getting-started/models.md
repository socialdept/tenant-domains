# Domain models

Part of the [tenant-domains docs](../introduction.md#documentation). The ownership token on your tenant, the trait on your domain model, and the config that points the package at them.

## The ownership token

Your tenant model supplies the token that ends up in the ownership TXT record. It has to be stable for the life of the tenant, because rotating it invalidates every domain already verified against it. It also must not be guessable from the tenant's public identity, or one tenant could claim a domain pointed at another.

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

The package reads the token from the domain model when it implements `ProvidesOwnershipToken` itself. Otherwise it follows the domain's `tenant` relation. If your relation has another name, set `tenant-domains.tenant_relation` to it. When neither has one, `instructionsFor()` and `verifyOwnership()` throw `MissingOwnershipToken`.

## The domain model

Mix the trait into your domain model and merge its casts:

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

No stancl? Extend `SocialDept\TenantDomains\Models\Domain` instead. It uses the trait already and reads its table from `tenant-domains.table`.

`domains:reconcile` sweeps the model named in `tenant-domains.model`, which is the packaged one by default. Point it at your own:

```php
// config/tenant-domains.php
'model' => App\Models\Domain::class,
```

## What the trait adds

A row is either a platform subdomain or a custom domain. A non-null `platform_base` means a platform subdomain, and `domain` holds the bare label (`acme`). A null `platform_base` means a custom domain, and `domain` holds the full name. The package never guesses this from whether `domain` contains a dot.

The trait gives the row these attributes:

| Attribute | What it is |
|---|---|
| `hostname` | The full name, with the platform base appended for a platform subdomain. Every DNS record is computed relative to it. |
| `address` | Where a visitor should end up, which is the `www.` host when the tenant chose `to_www`. See [Serving www](../domains/www.md). |
| `is_platform_subdomain`, `is_custom` | Which of the two kinds of row this is. |
| `is_apex` | A custom domain that is a registrable apex. |
| `record_prefix` | The Name field a DNS provider expects for the root record, null at the apex. |
| `acme_delegation_target` | Where DNS-01 challenges for this domain are written, null until it has a delegation id. |
| `is_verified` | In service. Platform subdomains always are. |
| `is_routable` | The edge should serve it. Wider than verified, because a domain being confirmed has to be reachable first. |
| `www_host`, `serves_www` | The `www.` host of an apex, and whether it is served. |

And these query scopes: `custom()`, `platform()`, `withStatus(DomainStatus ...$statuses)`, `routable()`, `servingHost($host)` and `servingHostByPrecedence($host)`. The last two are covered in [Serving www](../domains/www.md).

`status` is cast to `DomainStatus`: `pending`, `confirming`, `verified` or `failed`.
