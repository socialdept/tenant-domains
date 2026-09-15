<?php

namespace SocialDept\TenantDomains\Core;

use InvalidArgumentException;
use SocialDept\TenantDomains\Enums\CertificateMode;

/**
 * The platform's own identity: its domain, and every name derived from it.
 *
 * One object so the `{platform}` placeholder is expanded in exactly one place.
 * Both source apps interpolated `config('app.domain')` at a dozen call sites,
 * which is how one of them ended up with a delegation target that disagreed
 * with the record its own instructions displayed.
 */
final class Platform
{
    /**
     * @param  array<int, string>  $ingressIps
     */
    public function __construct(
        public readonly string $domain,
        public readonly string $cnameTarget,
        public readonly array $ingressIps,
        public readonly string $delegationSuffix,
        public readonly string $ownershipPrefix,
        public readonly string $ownershipValuePrefix,
        public readonly CertificateMode $certificateMode,
    ) {
        if ($this->domain === '') {
            throw new InvalidArgumentException(
                'tenant-domains.platform_domain is not set. It is the domain tenant subdomains live under and the zone ACME delegations are written into.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        $domain = DomainName::normalise((string) ($config['platform_domain'] ?? ''));

        $expand = static fn (string $value): string => str_replace('{platform}', $domain, $value);

        return new self(
            domain: $domain,
            cnameTarget: $expand((string) data_get($config, 'routing.cname_target', 'to.{platform}')),
            ingressIps: array_values(array_filter(array_map(
                static fn ($ip): string => trim((string) $ip),
                (array) data_get($config, 'routing.ingress_ips', []),
            ))),
            delegationSuffix: $expand((string) data_get($config, 'certificates.delegation_suffix', 'acme.{platform}')),
            ownershipPrefix: (string) data_get($config, 'ownership.prefix', '_verify'),
            ownershipValuePrefix: (string) data_get($config, 'ownership.value_prefix', 'verification='),
            certificateMode: CertificateMode::from(
                (string) data_get($config, 'certificates.mode', CertificateMode::DelegatedDns->value),
            ),
        );
    }

    /**
     * The ACME delegation target for one domain's delegation id.
     */
    public function delegationTarget(string $delegationId): string
    {
        return "{$delegationId}.{$this->delegationSuffix}";
    }

    /**
     * The TXT value proving ownership, for a tenant's token.
     */
    public function ownershipValue(string $token): string
    {
        return $this->ownershipValuePrefix.$token;
    }

    /**
     * The first ingress address, which is what apex instructions display. The rest
     * are still accepted when verifying.
     */
    public function primaryIngressIp(): ?string
    {
        return $this->ingressIps[0] ?? null;
    }

    /**
     * Whether a hostname belongs to the platform itself rather than a tenant.
     */
    public function owns(DomainName|string $host): bool
    {
        $host = $host instanceof DomainName ? $host : DomainName::make($host);

        return $host->isUnder($this->domain);
    }
}
