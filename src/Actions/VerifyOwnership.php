<?php

namespace SocialDept\TenantDomains\Actions;

use SocialDept\TenantDomains\Contracts\DnsResolver;
use SocialDept\TenantDomains\Core\DomainName;
use SocialDept\TenantDomains\Core\Platform;
use SocialDept\TenantDomains\Exceptions\DnsUnavailable;

/**
 * Whether a tenant has proved they control a domain.
 *
 * Checked separately from routing on purpose: a TXT record proves control
 * without moving any traffic, so a tenant can clear this step while their
 * existing site and email keep serving. It is also the proxy-proof signal, because
 * Cloudflare never proxies TXT records, so it resolves whatever their orange
 * cloud is doing.
 */
class VerifyOwnership
{
    public function __construct(
        private readonly DnsResolver $dns,
        private readonly Platform $platform,
    ) {
        //
    }

    /**
     * @throws DnsUnavailable when the lookup could not be made at all, which is
     *                        not the same as a missing record.
     */
    public function __invoke(DomainName|string $domain, string $token): bool
    {
        $domain = $domain instanceof DomainName ? $domain : DomainName::make($domain);
        $expected = $this->platform->ownershipValue($token);
        $host = $domain->recordHost($this->platform->ownershipPrefix);

        foreach ($this->dns->records($host, DNS_TXT) as $record) {
            // Long values arrive chunked, joined in `txt` and split in `entries`.
            $value = (string) ($record['txt'] ?? '');
            $joined = implode('', (array) ($record['entries'] ?? []));

            if ($value === $expected || $joined === $expected) {
                return true;
            }
        }

        return false;
    }
}
