<?php

namespace SocialDept\TenantDomains\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use SocialDept\TenantDomains\Core\DomainName;
use SocialDept\TenantDomains\Domains;

/**
 * The on-demand TLS permission endpoint, which Caddy calls `ask`.
 *
 * Caddy calls this during a TLS handshake for a hostname it has no certificate
 * for. A 200 authorises it to go and get one.
 *
 * **Deny by default.** In on-demand mode this endpoint is the only thing between
 * a port scanner and the Let's Encrypt rate limit for your entire registered
 * domain. One platform exhausted 50 certificates a week before hardening it.
 * Anything the host app does not explicitly claim gets a 404, and the refusal is
 * logged so a legitimate miss is diagnosable rather than invisible.
 */
class CertificateAuthorityController
{
    public function __construct(private readonly Domains $domains)
    {
    }

    public function __invoke(Request $request): Response
    {
        $host = (string) $request->query('domain', '');

        if ($host === '') {
            return new Response('Missing domain parameter', 400);
        }

        $name = DomainName::make($host);

        if (! $name->isValid()) {
            return new Response('Not a valid hostname', 400);
        }

        return $this->domains->mayIssueCertificateFor($name)
            ? new Response('OK', 200)
            : new Response('Not found', 404);
    }
}
