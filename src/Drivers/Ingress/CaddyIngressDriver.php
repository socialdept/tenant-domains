<?php

namespace SocialDept\TenantDomains\Drivers\Ingress;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SocialDept\TenantDomains\Contracts\IngressDriver;
use SocialDept\TenantDomains\Core\Platform;
use SocialDept\TenantDomains\Data\CertificateState;
use SocialDept\TenantDomains\Data\HostnameBinding;
use SocialDept\TenantDomains\Enums\CertificateMode;
use SocialDept\TenantDomains\Enums\IngressCapability;
use Throwable;

/**
 * Caddy, driven through its admin API.
 *
 * ## Delegated DNS-01, and why `override_domain` is the whole trick
 *
 * CertMagic's DNS solver does **not** follow the tenant's `_acme-challenge`
 * CNAME. It resolves the challenge name up to its SOA and hands that zone to the
 * provider, so for `_acme-challenge.example.com` it asks Cloudflare for an
 * `example.com` zone, finds none in our account, and fails with
 * "expected 1 zone, got 0". Silently, after the setup flow has already reported
 * success.
 *
 * `override_domain` replaces the record name outright, so the TXT is written at
 * the delegation target inside OUR zone. Let's Encrypt, which *does* follow
 * CNAMEs when validating, chases the tenant's record to that same target and
 * reads it there.
 *
 * The override is per-solver, therefore per-policy, therefore **one automation
 * policy per custom domain**. Everything else about this driver follows from
 * that constraint.
 */
class CaddyIngressDriver implements IngressDriver
{
    private const TLS_PATH = '/config/apps/tls';

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly Platform $platform,
        private readonly array $config = [],
    ) {
        //
    }

    public function publish(HostnameBinding $binding): void
    {
        if ($binding->hostnames === []) {
            return;
        }

        if ($this->platform->certificateMode === CertificateMode::OnDemand) {
            // Nothing to program. The ask endpoint is the entire gate.
            return;
        }

        if (! $binding->isDelegated()) {
            // The shared policy would look like it worked while every issuance
            // failed with "expected 1 zone, got 0", so refuse rather than poison it.
            Log::error('[tenant-domains] Skipping TLS for a custom domain with no ACME delegation target', [
                'hostnames' => $binding->hostnames,
            ]);

            return;
        }

        $this->mutateTls(function (array &$tls) use ($binding): bool {
            $changed = $this->automate($tls, $binding->hostnames);
            $changed = $this->evictFromOtherPolicies($tls, $binding->hostnames, $binding->acmeDelegationTarget) || $changed;

            return $this->upsertPolicy($tls, $binding) || $changed;
        });
    }

    public function withdraw(HostnameBinding $binding): void
    {
        if ($binding->hostnames === []) {
            return;
        }

        $this->mutateTls(function (array &$tls) use ($binding): bool {
            $changed = $this->deautomate($tls, $binding->hostnames);

            $policies = $tls['automation']['policies'] ?? [];
            $target = $binding->acmeDelegationTarget;

            foreach ($policies as $index => $policy) {
                if ($target !== null && $this->overrideDomainOf($policy) === $target) {
                    unset($policies[$index]);
                    $changed = true;
                }
            }

            $tls['automation']['policies'] = array_values($policies);

            return $changed;
        });
    }

    public function certificateState(string $host): CertificateState
    {
        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => false,
            'verify_peer_name' => false,
            'SNI_enabled' => true,
            'peer_name' => $host,
        ]]);

        $client = @stream_socket_client(
            "ssl://{$host}:443",
            $errno,
            $errstr,
            timeout: 10,
            flags: STREAM_CLIENT_CONNECT,
            context: $context,
        );

        if ($client === false) {
            // During setup this is the expected answer, not yet a failure.
            return CertificateState::failed($errstr !== '' ? $errstr : 'Could not establish a TLS connection');
        }

        $params = stream_context_get_params($client);
        fclose($client);

        $certificate = $params['options']['ssl']['peer_certificate'] ?? null;

        if ($certificate === null) {
            return CertificateState::failed('No certificate was returned');
        }

        $parsed = openssl_x509_parse($certificate) ?: [];

        return new CertificateState(
            provisioned: true,
            subject: $parsed['subject']['CN'] ?? null,
            issuer: $parsed['issuer']['O'] ?? $parsed['issuer']['CN'] ?? null,
            expiresAt: isset($parsed['validTo_time_t'])
                ? date('Y-m-d H:i:s', (int) $parsed['validTo_time_t'])
                : null,
        );
    }

    public function supports(IngressCapability $capability): bool
    {
        return match ($capability) {
            // HTTP-01 and TLS-ALPN cannot prove a wildcard, whatever Caddy is told.
            IngressCapability::Wildcard => $this->platform->certificateMode->supportsWildcards(),
            IngressCapability::PerHostnameOrigin => true,
            IngressCapability::RuntimeReconfiguration => true,
            IngressCapability::CertificateInspection => true,
        };
    }

    /**
     * A dedicated ACME policy for one custom domain, pinning the DNS-01
     * challenge record to that domain's delegation target.
     *
     * @return array<string, mixed>
     */
    public function buildPolicy(HostnameBinding $binding): array
    {
        return [
            'subjects' => array_values($binding->hostnames),
            'issuers' => [[
                'module' => 'acme',
                'challenges' => [
                    'dns' => [
                        'provider' => [
                            'name' => (string) ($this->config['dns_provider'] ?? 'cloudflare'),
                            'api_token' => (string) ($this->config['dns_api_token'] ?? '{env.CF_API_TOKEN}'),
                        ],
                        'resolvers' => array_values((array) ($this->config['resolvers'] ?? ['1.1.1.1', '8.8.8.8'])),
                        'propagation_delay' => (string) ($this->config['propagation_delay'] ?? '10s'),
                        'propagation_timeout' => (string) ($this->config['propagation_timeout'] ?? '300s'),
                        'override_domain' => $binding->acmeDelegationTarget,
                    ],
                ],
            ]],
        ];
    }

    /**
     * Insert or replace the policy for one binding.
     *
     * Keyed by delegation target rather than by subjects: two tenants can
     * transiently share a hostname during a migration, and the target is what
     * identifies whose policy this is.
     *
     * @param  array<string, mixed>  $tls
     */
    private function upsertPolicy(array &$tls, HostnameBinding $binding): bool
    {
        $policy = $this->buildPolicy($binding);
        $policies = $tls['automation']['policies'] ?? [];

        foreach ($policies as $index => $existing) {
            if ($this->overrideDomainOf($existing) !== $binding->acmeDelegationTarget) {
                continue;
            }

            if ($existing == $policy) {
                return false;
            }

            $policies[$index] = $policy;
            $tls['automation']['policies'] = array_values($policies);

            return true;
        }

        // Must land ahead of the subject-less catch-all or it is dead config.
        array_splice($policies, $this->catchAllIndex($policies), 0, [$policy]);
        $tls['automation']['policies'] = array_values($policies);

        return true;
    }

    /**
     * Older config, or another tool, may have appended these subjects to the
     * shared policy. Caddy matches policies in order and takes the first whose
     * subjects match, so a leftover entry there shadows ours and the delegated
     * solver never runs.
     *
     * @param  array<string, mixed>  $tls
     * @param  array<int, string>  $subjects
     */
    private function evictFromOtherPolicies(array &$tls, array $subjects, ?string $keepTarget): bool
    {
        $policies = $tls['automation']['policies'] ?? [];
        $changed = false;

        foreach ($policies as $index => $policy) {
            if ($this->overrideDomainOf($policy) === $keepTarget) {
                continue;
            }

            // A catch-all matches everything by design.
            if (! isset($policy['subjects']) || $policy['subjects'] === []) {
                continue;
            }

            $remaining = array_values(array_diff($policy['subjects'], $subjects));

            if ($remaining === $policy['subjects']) {
                continue;
            }

            $changed = true;

            if ($remaining === []) {
                unset($policies[$index]);

                continue;
            }

            $policies[$index]['subjects'] = $remaining;
        }

        if ($changed) {
            $tls['automation']['policies'] = array_values($policies);
        }

        return $changed;
    }

    /**
     * Where the first catch-all policy sits, or the end of the list.
     *
     * @param  array<int, array<string, mixed>>  $policies
     */
    private function catchAllIndex(array $policies): int
    {
        foreach (array_values($policies) as $index => $policy) {
            if (! isset($policy['subjects']) || $policy['subjects'] === []) {
                return $index;
            }
        }

        return count($policies);
    }

    /**
     * The delegation target a policy is pinned to, if it is one of ours.
     *
     * @param  array<string, mixed>  $policy
     */
    private function overrideDomainOf(array $policy): ?string
    {
        foreach ($policy['issuers'] ?? [] as $issuer) {
            $override = $issuer['challenges']['dns']['override_domain'] ?? null;

            if (is_string($override)) {
                return $override;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $tls
     * @param  array<int, string>  $subjects
     */
    private function automate(array &$tls, array $subjects): bool
    {
        $existing = $tls['certificates']['automate'] ?? [];
        $merged = array_values(array_unique([...$existing, ...$subjects]));

        if ($merged === array_values($existing)) {
            return false;
        }

        $tls['certificates']['automate'] = $merged;

        return true;
    }

    /**
     * @param  array<string, mixed>  $tls
     * @param  array<int, string>  $subjects
     */
    private function deautomate(array &$tls, array $subjects): bool
    {
        $existing = $tls['certificates']['automate'] ?? [];
        $remaining = array_values(array_diff($existing, $subjects));

        if ($remaining === array_values($existing)) {
            return false;
        }

        $tls['certificates']['automate'] = $remaining;

        return true;
    }

    /**
     * Read the live TLS config, apply a mutation, and write it back, in one
     * pass.
     *
     * Single-pass on purpose. Every read-modify-write against the admin API is a
     * lost-update window, and a half-applied state here (a subject automated
     * with no policy that can issue it, or a subject left on the shared policy)
     * is exactly the broken configuration this driver exists to prevent.
     *
     * @param  callable(array<string, mixed>): bool  $mutate
     */
    private function mutateTls(callable $mutate): bool
    {
        try {
            $response = Http::timeout(10)->get($this->adminUrl(self::TLS_PATH));

            if (! $response->successful()) {
                Log::error('[tenant-domains] Could not read the Caddy TLS config', [
                    'status' => $response->status(),
                ]);

                return false;
            }

            $tls = $response->json() ?? [];
            $tls['automation']['policies'] ??= [];
            $tls['certificates']['automate'] ??= [];

            if (! $mutate($tls)) {
                return true;
            }

            $write = Http::timeout(15)
                ->asJson()
                ->patch($this->adminUrl(self::TLS_PATH), $tls);

            if (! $write->successful()) {
                Log::error('[tenant-domains] Could not write the Caddy TLS config', [
                    'status' => $write->status(),
                    'body' => $write->body(),
                ]);

                return false;
            }

            return true;
        } catch (Throwable $e) {
            Log::error('[tenant-domains] Caddy admin API is unreachable', ['error' => $e->getMessage()]);

            return false;
        }
    }

    private function adminUrl(string $path): string
    {
        $base = rtrim((string) ($this->config['admin_url'] ?? 'http://localhost:2019'), '/');

        return $base.$path;
    }
}
