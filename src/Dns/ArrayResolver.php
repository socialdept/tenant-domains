<?php

namespace SocialDept\TenantDomains\Dns;

use SocialDept\TenantDomains\Contracts\DnsResolver;
use SocialDept\TenantDomains\Exceptions\DnsUnavailable;

/**
 * A resolver backed by a fixture array, for tests.
 *
 * Fixtures are written the way a person reads DNS, not the way
 * `dns_get_record()` returns it:
 *
 * ```php
 * new ArrayResolver([
 *     'blog.example.com' => ['CNAME' => 'to.platform.test'],
 *     '_own.blog.example.com' => ['TXT' => 'verification=abc'],
 *     'example.com' => ['NS' => ['ns1.cloudflare.com', 'ns2.cloudflare.com']],
 *     'to.platform.test' => ['A' => '203.0.113.10'],
 * ]);
 * ```
 *
 * Single values and lists are both accepted. A host absent from the fixture
 * resolves to nothing, which is the ordinary "record not created yet" case.
 */
class ArrayResolver implements DnsResolver
{
    /**
     * @var array<string, array<string, array<int, string>>>
     */
    private array $zone = [];

    /**
     * Hosts whose lookups fail outright rather than returning nothing.
     *
     * @var array<int, string>
     */
    private array $unavailable = [];

    /**
     * @param  array<string, array<string, string|array<int, string>>>  $zone
     */
    public function __construct(array $zone = [])
    {
        foreach ($zone as $host => $records) {
            $this->set($host, $records);
        }
    }

    /**
     * Add or replace a host's records.
     *
     * @param  array<string, string|array<int, string>>  $records
     */
    public function set(string $host, array $records): self
    {
        $normalised = [];

        foreach ($records as $type => $values) {
            $normalised[strtoupper($type)] = array_values((array) $values);
        }

        $this->zone[$this->key($host)] = $normalised;

        return $this;
    }

    /**
     * Make lookups for a host throw, simulating a resolver outage rather than a
     * missing record.
     */
    public function fail(string $host): self
    {
        $this->unavailable[] = $this->key($host);

        return $this;
    }

    public function records(string $host, int $type): array
    {
        $this->guard($host);

        $name = $this->nameFor($type);
        $values = $this->zone[$this->key($host)][$name] ?? [];

        return array_map(
            fn (string $value): array => $this->shape($host, $name, $value),
            $values,
        );
    }

    public function ips(string $host): array
    {
        $this->guard($host);

        $records = $this->zone[$this->key($host)] ?? [];

        if (isset($records['A'])) {
            return $records['A'];
        }

        // One hop only, so an accidental fixture loop cannot hang the suite.
        if (isset($records['CNAME'][0])) {
            return $this->zone[$this->key($records['CNAME'][0])]['A'] ?? [];
        }

        return [];
    }

    /**
     * @throws DnsUnavailable
     */
    private function guard(string $host): void
    {
        if (in_array($this->key($host), $this->unavailable, true)) {
            throw DnsUnavailable::forHost($host);
        }
    }

    /**
     * One record in `dns_get_record()` shape, so callers can be written against
     * the real thing and exercised against this.
     *
     * @return array<string, mixed>
     */
    private function shape(string $host, string $type, string $value): array
    {
        $record = ['host' => $host, 'class' => 'IN', 'ttl' => 300, 'type' => $type];

        return match ($type) {
            'A' => $record + ['ip' => $value],
            'AAAA' => $record + ['ipv6' => $value],
            'TXT' => $record + ['txt' => $value, 'entries' => [$value]],
            default => $record + ['target' => $value],
        };
    }

    private function nameFor(int $type): string
    {
        return match ($type) {
            DNS_A => 'A',
            DNS_AAAA => 'AAAA',
            DNS_CNAME => 'CNAME',
            DNS_TXT => 'TXT',
            DNS_NS => 'NS',
            DNS_MX => 'MX',
            default => 'UNKNOWN',
        };
    }

    private function key(string $host): string
    {
        return strtolower(rtrim(trim($host), '.'));
    }
}
