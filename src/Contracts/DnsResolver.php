<?php

namespace SocialDept\TenantDomains\Contracts;

use SocialDept\TenantDomains\Exceptions\DnsUnavailable;

/**
 * Reading public DNS.
 *
 * An interface rather than direct `dns_get_record()` calls so verification can
 * be tested without live DNS. Both source apps either hit the network in their
 * test suites or subclassed actions to stub a protected method. Neither scales.
 *
 * Implementations MUST distinguish "the record is not there" (empty array) from
 * "we could not ask" (throw {@see DnsUnavailable}).
 */
interface DnsResolver
{
    /**
     * Records of one type for a host, in `dns_get_record()` shape.
     *
     * @param  int  $type  One of the DNS_* constants.
     * @return array<int, array<string, mixed>>
     *
     * @throws DnsUnavailable
     */
    public function records(string $host, int $type): array;

    /**
     * The IPv4 addresses a host resolves to, following CNAMEs.
     *
     * @return array<int, string>
     *
     * @throws DnsUnavailable
     */
    public function ips(string $host): array;
}
