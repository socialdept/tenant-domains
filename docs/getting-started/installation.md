# Installation

Part of the [tenant-domains docs](../introduction.md#documentation). Installing the package, its config, the environment variables and the database.

## Installing

```bash
composer require socialdept/tenant-domains
php artisan vendor:publish --tag=tenant-domains-config
```

`stancl/tenancy` is optional. The package works with its `domains` table and its `Domain` model when it is installed, and ships a model of its own when it is not.

## Configuration

The config lives in `config/tenant-domains.php`. Point the package at your platform in `.env`:

```dotenv
TENANT_DOMAINS_PLATFORM=yourapp.com
TENANT_DOMAINS_INGRESS_IPS=203.0.113.10
TENANT_DOMAINS_CERT_MODE=delegated_dns
TENANT_DOMAINS_INGRESS_DRIVER=caddy
```

| Variable | Config key | Default |
|---|---|---|
| `TENANT_DOMAINS_PLATFORM` | `platform_domain` | `APP_DOMAIN` |
| `TENANT_DOMAINS_INGRESS_IPS` | `routing.ingress_ips` | none, comma separated |
| `TENANT_DOMAINS_CNAME_TARGET` | `routing.cname_target` | `to.{platform}` |
| `TENANT_DOMAINS_OWNERSHIP_PREFIX` | `ownership.prefix` | `_verify` |
| `TENANT_DOMAINS_OWNERSHIP_VALUE_PREFIX` | `ownership.value_prefix` | `verification=` |
| `TENANT_DOMAINS_CERT_MODE` | `certificates.mode` | `delegated_dns` |
| `TENANT_DOMAINS_DELEGATION_SUFFIX` | `certificates.delegation_suffix` | `acme.{platform}` |
| `TENANT_DOMAINS_ZONE_DRIVER` | `drivers.zone` | `null` |
| `TENANT_DOMAINS_INGRESS_DRIVER` | `drivers.ingress` | `null` |
| `TENANT_DOMAINS_AUTHORITY_PATH` | `authority_endpoint.path` | `api/caddy/verify` |
| `TENANT_DOMAINS_DNS_CANARY` | `dns.canary` | `1.1.1.1` |

The platform domain is required. It is the domain tenant subdomains live under and the zone ACME delegation targets are written into, and every `{platform}` placeholder in the config is replaced with it. The settings for each driver are listed in [Drivers](../drivers.md), and [DNS records](../domains/records.md) covers the names tenants are given.

`dns.canary` is a hostname that must always resolve. When a lookup fails, the canary is asked too. If it answers, the failure was about the tenant's host. If it fails as well, DNS is unavailable and the tenant is told so rather than blamed for a missing record.

Apex detection uses the Public Suffix List copy that ships with the package. To keep your own, copy the list to a writable path, point `psl_path` at it, and refresh it with:

```bash
php artisan domains:refresh-psl
```

The command downloads the list from `https://publicsuffix.org/list/public_suffix_list.dat` by default, and `--url` takes another source.

## Database

Publish one of the two migrations, then run them:

```bash
# A fresh domains table...
php artisan vendor:publish --tag=tenant-domains-migrations

# ...or, if you already have one (stancl/tenancy does), just the extra columns:
php artisan vendor:publish --tag=tenant-domains-migrations-columns

php artisan migrate
```

The table is named by `table`, `domains` by default. The columns migration adds only the columns that are missing, so it is safe on a table that already has some of them. It also fills in `platform_base` for existing rows whose `domain` has no dot, since those are platform subdomains under the current platform domain.
