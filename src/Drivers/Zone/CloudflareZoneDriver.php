<?php

namespace SocialDept\TenantDomains\Drivers\Zone;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use SocialDept\TenantDomains\Contracts\ZoneDriver;
use SocialDept\TenantDomains\Data\DnsRecord;

/**
 * Cloudflare DNS for the platform's own zone.
 *
 * Operations are idempotent and keyed by type + name, so no Cloudflare record
 * ids need persisting, because state is always reconcilable from the database.
 */
class CloudflareZoneDriver implements ZoneDriver
{
    private const BASE = 'https://api.cloudflare.com/client/v4';

    public function __construct(
        private readonly string $apiToken,
        private readonly string $zoneId,
        private readonly int $ttl = 1,
    ) {
        //
    }

    public function upsert(DnsRecord $record): bool
    {
        if (! $this->configured()) {
            return false;
        }

        $payload = [
            'type' => $record->type,
            'name' => $record->host,
            'content' => $record->value,
            'ttl' => $this->ttl,
            'proxied' => $record->proxied,
        ];

        $existing = $this->findRaw($record->type, $record->host);

        $response = $existing === null
            ? $this->request()->post($this->url('dns_records'), $payload)
            : $this->request()->put($this->url("dns_records/{$existing['id']}"), $payload);

        return $response->successful();
    }

    public function delete(string $type, string $name): bool
    {
        if (! $this->configured()) {
            return false;
        }

        $existing = $this->findRaw($type, $name);

        if ($existing === null) {
            // Already absent is the desired end state.
            return true;
        }

        return $this->request()->delete($this->url("dns_records/{$existing['id']}"))->successful();
    }

    public function find(string $type, string $name): ?DnsRecord
    {
        $record = $this->findRaw($type, $name);

        if ($record === null) {
            return null;
        }

        return new DnsRecord(
            type: (string) $record['type'],
            name: (string) $record['name'],
            host: (string) $record['name'],
            value: (string) $record['content'],
            proxied: (bool) ($record['proxied'] ?? false),
        );
    }

    public function configured(): bool
    {
        return $this->apiToken !== '' && $this->zoneId !== '';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findRaw(string $type, string $name): ?array
    {
        if (! $this->configured()) {
            return null;
        }

        $response = $this->request()->get($this->url('dns_records'), [
            'type' => $type,
            'name' => $name,
        ]);

        if (! $response->successful()) {
            return null;
        }

        return $response->json('result.0');
    }

    private function request(): PendingRequest
    {
        return Http::withToken($this->apiToken)
            ->acceptJson()
            ->timeout(15);
    }

    private function url(string $path): string
    {
        return self::BASE."/zones/{$this->zoneId}/{$path}";
    }
}
