# Certificate modes

Part of the [tenant-domains docs](../introduction.md#documentation). Delegated DNS-01 or on-demand certificates, and how the delegation works.

## Choosing a mode

`certificates.mode` is the single most consequential setting. It decides what your platform can offer.

| | `delegated_dns` | `on_demand` |
|---|---|---|
| Challenge | DNS-01 in your zone | HTTP-01 or TLS-ALPN |
| Needs | admin API plus a zone you control | nothing |
| **Wildcards** | **yes** | **no.** Neither challenge can prove one |
| Tenant behind a CDN | fine | fragile |
| Per-domain edge config | one policy each | none |

`delegated_dns` is the default. Choose `on_demand` only if every custom domain shares one origin and you will never need a wildcard. In `on_demand` mode no `_acme-challenge` record is issued, and the edge asks the [certificate authority endpoint](authority-endpoint.md) at handshake time instead.

The package refuses a wildcard binding outright rather than letting it silently never get a certificate: `publish()` throws `UnsupportedCapability` when the domain needs a wildcard the configured edge cannot issue.

## How the delegation works

Each domain gets a delegation id, minted once by `ensureDelegationId()`, and the tenant CNAMEs `_acme-challenge` to `{id}.{delegation_suffix}`. Ids are 12 characters from an alphabet without look-alike characters, because they get read aloud and typed by hand (`certificates.id_length` and `certificates.id_alphabet`). Never regenerate one that has been issued.

CertMagic does **not** follow the tenant's `_acme-challenge` CNAME. It resolves the challenge name to its SOA and asks your DNS provider for that zone, which you do not own, then fails with `expected 1 zone, got 0`. Silently, after setup already reported success.

The fix is `override_domain`, which this package writes into a per-domain automation policy. It replaces the record name outright, so the TXT lands at the delegation target inside your zone. Let's Encrypt does follow CNAMEs when validating, so it chases the tenant's record to the same place and reads it there.

The override is per solver, so it is per policy, so it is one policy per custom domain. The [Caddy driver](../drivers.md#caddy) manages those policies through the admin API.

## Resources

- [Caddy TLS automation](https://caddyserver.com/docs/json/apps/tls/) is the config the Caddy driver writes.
- [RFC 8555](https://datatracker.ietf.org/doc/html/rfc8555), section 8.4, covers DNS-01 challenges.
