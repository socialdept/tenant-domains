<?php

namespace SocialDept\TenantDomains\Data;

use Illuminate\Contracts\Support\Arrayable;
use SocialDept\TenantDomains\Core\DomainName;
use SocialDept\TenantDomains\Core\Platform;
use SocialDept\TenantDomains\Enums\RoutingMode;

/**
 * The records a tenant must create, and the context a UI needs to explain them.
 *
 * The single source of truth for every surface that shows DNS setup. Both
 * source apps built this twice, once for the setup wizard and once for the
 * settings dialog, and the two drifted.
 *
 * @implements Arrayable<string, mixed>
 */
final class Instructions implements Arrayable
{
    /**
     * @param  array<string, DnsRecord>  $records  Keyed by purpose: root, wildcard, ownership, acme.
     */
    public function __construct(
        public readonly DomainName $domain,
        public readonly array $records,
        public readonly RoutingMode $routingMode,
        public readonly DomainRequirements $requirements,
        public readonly bool $isApex,
        public readonly ?string $providerName = null,
        public readonly bool $isCloudflare = false,
        public readonly ?bool $supportsApexCname = null,
    ) {
        //
    }

    public function record(string $purpose): ?DnsRecord
    {
        return $this->records[$purpose] ?? null;
    }

    /**
     * Whether the tenant may choose between a CNAME and an A record.
     *
     * Only at the apex. A subdomain always takes a CNAME, and offering a choice
     * there is an invitation to pick the worse one.
     */
    public function routingModeIsChoosable(): bool
    {
        return $this->isApex;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'domain' => $this->domain->value,
            'isApex' => $this->isApex,
            'routingMode' => $this->routingMode->value,
            'routingModeIsChoosable' => $this->routingModeIsChoosable(),
            'requirements' => $this->requirements->toArray(),
            'providerName' => $this->providerName,
            'isCloudflare' => $this->isCloudflare,
            'supportsApexCname' => $this->supportsApexCname,
            'records' => array_map(
                static fn (DnsRecord $record): array => $record->toArray(),
                $this->records,
            ),
        ];
    }

    /**
     * Build the record set for a domain.
     *
     * `$delegationId` is null in on-demand certificate mode, and no ACME record
     * is emitted. A record nothing reads is worse than no record.
     */
    public static function build(
        DomainName $domain,
        Platform $platform,
        DomainRequirements $requirements,
        RoutingMode $routingMode,
        string $ownershipToken,
        ?string $delegationId = null,
        ?string $providerName = null,
        bool $isCloudflare = false,
        ?bool $supportsApexCname = null,
    ): self {
        $records = [];

        if ($requirements->root) {
            $records['root'] = $routingMode === RoutingMode::ARecord
                ? new DnsRecord(
                    type: 'A',
                    name: $domain->recordName(),
                    host: $domain->value,
                    value: (string) $platform->primaryIngressIp(),
                    purpose: 'root',
                )
                : new DnsRecord(
                    type: 'CNAME',
                    name: $domain->recordName(),
                    host: $domain->value,
                    value: $platform->cnameTarget,
                    purpose: 'root',
                );
        }

        if ($requirements->wildcard) {
            $records['wildcard'] = new DnsRecord(
                type: 'CNAME',
                name: $domain->recordName('*'),
                host: $domain->wildcard(),
                value: $platform->cnameTarget,
                purpose: 'wildcard',
            );
        }

        $records['ownership'] = new DnsRecord(
            type: 'TXT',
            name: $domain->recordName($platform->ownershipPrefix),
            host: $domain->recordHost($platform->ownershipPrefix),
            value: $platform->ownershipValue($ownershipToken),
            purpose: 'ownership',
        );

        if ($platform->certificateMode->needsDelegationRecord() && $delegationId !== null) {
            $records['acme'] = new DnsRecord(
                type: 'CNAME',
                name: $domain->recordName('_acme-challenge'),
                host: $domain->recordHost('_acme-challenge'),
                value: $platform->delegationTarget($delegationId),
                purpose: 'acme',
            );
        }

        return new self(
            domain: $domain,
            records: $records,
            routingMode: $routingMode,
            requirements: $requirements,
            isApex: $domain->isApex(),
            providerName: $providerName,
            isCloudflare: $isCloudflare,
            supportsApexCname: $supportsApexCname,
        );
    }
}
