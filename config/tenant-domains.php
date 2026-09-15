<?php

use SocialDept\TenantDomains\Enums\CertificateMode;

return [

    /*
    |--------------------------------------------------------------------------
    | Platform domain
    |--------------------------------------------------------------------------
    |
    | Your own domain: the one tenant subdomains live under, and the zone the
    | ACME delegation targets are written into. Every {platform} placeholder
    | below is replaced with this value.
    |
    */

    'platform_domain' => env('TENANT_DOMAINS_PLATFORM', env('APP_DOMAIN')),

    /*
    |--------------------------------------------------------------------------
    | Ownership record
    |--------------------------------------------------------------------------
    |
    | The TXT record a tenant creates to prove they control a domain, before any
    | traffic moves. TXT is never proxied, so this resolves regardless of the
    | tenant's CDN.
    |
    | Both values are matched against records that already exist in the wild, so
    | changing them on a live platform invalidates every domain's proof. Pick
    | them once.
    |
    */

    'ownership' => [
        'prefix' => env('TENANT_DOMAINS_OWNERSHIP_PREFIX', '_verify'),
        'value_prefix' => env('TENANT_DOMAINS_OWNERSHIP_VALUE_PREFIX', 'verification='),
    ],

    /*
    |--------------------------------------------------------------------------
    | Routing
    |--------------------------------------------------------------------------
    |
    | Where a tenant points their domain. Subdomains CNAME to the target below.
    | Apex domains on registrars that cannot CNAME the root use an A record
    | instead.
    |
    | `ingress_ips` is every address an A record may legitimately resolve to:
    | the origin, plus any passthrough edge in front of it. The first is what
    | the instructions show, and all of them verify. It is a list because a single
    | value reports your own edge as a misconfiguration.
    |
    */

    'routing' => [
        'cname_target' => env('TENANT_DOMAINS_CNAME_TARGET', 'to.{platform}'),
        'ingress_ips' => array_values(array_filter(
            explode(',', (string) env('TENANT_DOMAINS_INGRESS_IPS', '')),
        )),
    ],

    /*
    |--------------------------------------------------------------------------
    | Certificates
    |--------------------------------------------------------------------------
    |
    | How certificates are obtained. This decides what the platform can offer:
    |
    |   delegated_dns  DNS-01 solved in your zone through the tenant's
    |                  `_acme-challenge` CNAME. Supports wildcards, unaffected
    |                  by any CDN in front of the tenant's domain.
    |
    |   on_demand      The edge asks you at handshake time and solves HTTP-01 /
    |                  TLS-ALPN itself. No per-domain setup, but no wildcards,
    |                  and the challenge travels the public request path.
    |
    | `delegation_suffix` is the zone delegation targets are minted under. Only
    | used in delegated_dns mode. In on_demand mode no delegation record is issued
    | or requested, because asking a tenant for a record nothing reads is worse
    | than not asking.
    |
    */

    'certificates' => [
        'mode' => env('TENANT_DOMAINS_CERT_MODE', CertificateMode::DelegatedDns->value),
        'delegation_suffix' => env('TENANT_DOMAINS_DELEGATION_SUFFIX', 'acme.{platform}'),

        // Delegation ids are read aloud and typed by hand, so the alphabet omits
        // look-alike characters. Never regenerate one that has been issued.
        'id_length' => 12,
        'id_alphabet' => '234567bdghjklmnpqrvwxyz',
    ],

    /*
    |--------------------------------------------------------------------------
    | Drivers
    |--------------------------------------------------------------------------
    */

    'drivers' => [
        'zone' => env('TENANT_DOMAINS_ZONE_DRIVER', 'null'),
        'ingress' => env('TENANT_DOMAINS_INGRESS_DRIVER', 'null'),

        'zones' => [
            'cloudflare' => [
                'api_token' => env('CLOUDFLARE_API_TOKEN'),
                'zone_id' => env('CLOUDFLARE_ZONE_ID'),
            ],
        ],

        'ingresses' => [
            'caddy' => [
                'admin_url' => env('CADDY_ADMIN_URL', 'http://localhost:2019'),

                // How Caddy authenticates to the DNS provider when solving
                // DNS-01. The token is written as a Caddy env placeholder, not
                // the secret itself, because the admin API config is readable.
                'dns_provider' => env('CADDY_DNS_PROVIDER', 'cloudflare'),
                'dns_api_token' => env('CADDY_DNS_TOKEN_PLACEHOLDER', '{env.CF_API_TOKEN}'),
                'resolvers' => ['1.1.1.1', '8.8.8.8'],
                'propagation_delay' => '10s',
                'propagation_timeout' => '300s',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Certificate authority endpoint
    |--------------------------------------------------------------------------
    |
    | The endpoint your edge calls before obtaining a certificate for a hostname
    | it does not recognise. Caddy calls this `ask`.
    |
    | Set `enabled` to false and register the route yourself if you want it under
    | your own API conventions, behind your own middleware, or on a different
    | router entirely:
    |
    |     Route::get('internal/tls/authorize', CertificateAuthorityController::class);
    |
    | Whatever path you choose has to match the edge config. In Caddy that is
    | the `ask` value in the `on_demand_tls` block.
    |
    | Keep it unauthenticated but unreachable from outside. The edge calls it
    | over loopback, and a public one leaks which domains you host.
    |
    */

    'authority_endpoint' => [
        'enabled' => true,
        'path' => env('TENANT_DOMAINS_AUTHORITY_PATH', 'api/caddy/verify'),
        'middleware' => [],
        'name' => 'tenant-domains.authority',
    ],

    /*
    |--------------------------------------------------------------------------
    | Reachability
    |--------------------------------------------------------------------------
    |
    | DNS resolving is not the same as a request arriving. After a domain is
    | routed, one real HTTPS request is made to it and the response inspected,
    | which is the only way to catch a redirect rule or worker intercepting the
    | domain in front of you.
    |
    | Redirects are never followed: a redirect is the failure being hunted, and
    | following one hides it.
    |
    */

    'reachability' => [
        'enabled' => true,
        'path' => '/',
        'timeout' => 8,
    ],

    /*
    |--------------------------------------------------------------------------
    | Reconciliation
    |--------------------------------------------------------------------------
    |
    | `domains:reconcile` re-checks pending domains so one whose records
    | propagated after the tenant closed the tab still completes.
    |
    */

    'reconcile' => [
        'batch' => 100,

        // Skip domains checked this recently. Stops overlapping runs, or a
        // manual invocation next to a scheduled one, doubling up DNS lookups
        // and edge writes for the same domain seconds apart. Set 0 to disable.
        'throttle' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    */

    'table' => 'domains',

    // The model `domains:reconcile` sweeps. Point this at your own once you have
    // mixed in the IsCustomDomain trait.
    'model' => SocialDept\TenantDomains\Models\Domain::class,

    /*
    |--------------------------------------------------------------------------
    | DNS
    |--------------------------------------------------------------------------
    |
    | `canary` is a hostname that must always resolve. When a lookup fails, it is
    | asked too: if the canary answers, the resolver is fine and the original
    | failure was about the host. If it fails as well, DNS is unavailable and the
    | tenant is told so rather than blamed for a missing record.
    |
    */

    'dns' => [
        'canary' => env('TENANT_DOMAINS_DNS_CANARY', '1.1.1.1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Public Suffix List
    |--------------------------------------------------------------------------
    |
    | Decides whether a domain is an apex, and so which routing record a tenant
    | is told to create. The packaged copy is used unless you point this at your
    | own, refreshed with `domains:refresh-psl`.
    |
    */

    'psl_path' => null,

];
