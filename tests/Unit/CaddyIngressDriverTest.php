<?php

namespace SocialDept\TenantDomains\Tests\Unit;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use SocialDept\TenantDomains\Core\Platform;
use SocialDept\TenantDomains\Data\HostnameBinding;
use SocialDept\TenantDomains\Drivers\Ingress\CaddyIngressDriver;
use SocialDept\TenantDomains\Enums\CertificateMode;
use SocialDept\TenantDomains\Enums\IngressCapability;
use SocialDept\TenantDomains\Tests\TestCase;

class CaddyIngressDriverTest extends TestCase
{
    /**
     * The whole reason this driver exists. CertMagic does not follow the
     * tenant's `_acme-challenge` CNAME. It resolves the challenge name to its SOA
     * and asks the provider for that zone, which we do not own.
     * `override_domain` writes the TXT at the delegation target inside our zone
     * instead.
     */
    #[Test]
    public function the_policy_pins_the_dns_challenge_to_the_delegation_target(): void
    {
        $policy = $this->driver()->buildPolicy(new HostnameBinding(
            hostnames: ['example.com', '*.example.com'],
            acmeDelegationTarget: 'abc123.acme.platform.test',
        ));

        $this->assertSame(['example.com', '*.example.com'], $policy['subjects']);

        $dns = $policy['issuers'][0]['challenges']['dns'];

        $this->assertSame('abc123.acme.platform.test', $dns['override_domain']);
        $this->assertSame('cloudflare', $dns['provider']['name']);

        // The env placeholder, not the secret. The admin API config is readable.
        $this->assertSame('{env.CF_API_TOKEN}', $dns['provider']['api_token']);
    }

    #[Test]
    public function publishing_adds_the_subjects_and_a_policy(): void
    {
        $this->fakeCaddy(['automation' => ['policies' => []], 'certificates' => ['automate' => []]]);

        $this->driver()->publish(new HostnameBinding(
            hostnames: ['example.com'],
            acmeDelegationTarget: 'abc123.acme.platform.test',
        ));

        $written = $this->writtenConfig();

        $this->assertSame(['example.com'], $written['certificates']['automate']);
        $this->assertCount(1, $written['automation']['policies']);
        $this->assertSame(
            'abc123.acme.platform.test',
            $written['automation']['policies'][0]['issuers'][0]['challenges']['dns']['override_domain'],
        );
    }

    /**
     * Reconciliation runs on a schedule. An edge that rewrites its config every
     * few minutes drops connections and re-issues certificates it already holds.
     */
    #[Test]
    public function publishing_an_unchanged_binding_writes_nothing(): void
    {
        $binding = new HostnameBinding(
            hostnames: ['example.com'],
            acmeDelegationTarget: 'abc123.acme.platform.test',
        );

        $this->fakeCaddy([
            'automation' => ['policies' => [$this->driver()->buildPolicy($binding)]],
            'certificates' => ['automate' => ['example.com']],
        ]);

        $this->driver()->publish($binding);

        // The GET that reads the current config is expected. The PATCH is not.
        Http::assertNotSent(fn ($request) => $request->method() === 'PATCH');
    }

    /**
     * Caddy matches policies in order and takes the first whose subjects match,
     * so an entry left on the shared policy shadows ours and the delegated
     * solver never runs.
     */
    #[Test]
    public function publishing_evicts_the_subjects_from_a_shared_policy(): void
    {
        $this->fakeCaddy([
            'automation' => ['policies' => [
                ['subjects' => ['platform.test', 'example.com'], 'issuers' => [['module' => 'acme']]],
            ]],
            'certificates' => ['automate' => ['platform.test', 'example.com']],
        ]);

        $this->driver()->publish(new HostnameBinding(
            hostnames: ['example.com'],
            acmeDelegationTarget: 'abc123.acme.platform.test',
        ));

        $policies = $this->writtenConfig()['automation']['policies'];

        $shared = collect($policies)->firstWhere('subjects', ['platform.test']);
        $this->assertNotNull($shared, 'The shared policy should keep its own subject and lose ours.');
    }

    /**
     * A catch-all has no subjects, so it matches everything. A delegated policy
     * landing behind it is dead config.
     */
    #[Test]
    public function the_policy_is_inserted_ahead_of_the_on_demand_catch_all(): void
    {
        $this->fakeCaddy([
            'automation' => ['policies' => [['on_demand' => true]]],
            'certificates' => ['automate' => []],
        ]);

        $this->driver()->publish(new HostnameBinding(
            hostnames: ['example.com'],
            acmeDelegationTarget: 'abc123.acme.platform.test',
        ));

        $policies = $this->writtenConfig()['automation']['policies'];

        $this->assertSame(['example.com'], $policies[0]['subjects']);
        $this->assertTrue($policies[1]['on_demand']);
    }

    /**
     * A leftover policy keeps the edge retrying ACME against a delegation record
     * the tenant has every reason to have deleted.
     */
    #[Test]
    public function withdrawing_removes_both_the_subjects_and_the_policy(): void
    {
        $binding = new HostnameBinding(
            hostnames: ['example.com'],
            acmeDelegationTarget: 'abc123.acme.platform.test',
        );

        $this->fakeCaddy([
            'automation' => ['policies' => [$this->driver()->buildPolicy($binding), ['on_demand' => true]]],
            'certificates' => ['automate' => ['platform.test', 'example.com']],
        ]);

        $this->driver()->withdraw($binding);

        $written = $this->writtenConfig();

        $this->assertSame(['platform.test'], $written['certificates']['automate']);
        $this->assertCount(1, $written['automation']['policies']);
        $this->assertTrue($written['automation']['policies'][0]['on_demand']);
    }

    /**
     * Putting an undelegated custom domain on the shared policy looks like it
     * worked while every issuance fails with "expected 1 zone, got 0".
     */
    #[Test]
    public function a_custom_domain_with_no_delegation_target_is_refused_not_guessed(): void
    {
        $this->fakeCaddy(['automation' => ['policies' => []], 'certificates' => ['automate' => []]]);

        $this->driver()->publish(new HostnameBinding(hostnames: ['example.com']));

        Http::assertNothingSent();
    }

    /**
     * The capability that earns its keep: HTTP-01 and TLS-ALPN cannot prove a
     * wildcard, so on-demand mode can never issue one however Caddy is set up.
     */
    #[Test]
    public function on_demand_mode_reports_that_it_cannot_do_wildcards(): void
    {
        $delegated = $this->driver(CertificateMode::DelegatedDns);
        $onDemand = $this->driver(CertificateMode::OnDemand);

        $this->assertTrue($delegated->supports(IngressCapability::Wildcard));
        $this->assertFalse($onDemand->supports(IngressCapability::Wildcard));
    }

    /**
     * In on-demand mode there is no per-domain config to write. The ask endpoint
     * is the entire gate.
     */
    #[Test]
    public function on_demand_mode_programs_nothing(): void
    {
        $this->fakeCaddy(['automation' => ['policies' => []], 'certificates' => ['automate' => []]]);

        $this->driver(CertificateMode::OnDemand)->publish(new HostnameBinding(
            hostnames: ['example.com'],
            acmeDelegationTarget: 'abc123.acme.platform.test',
        ));

        Http::assertNothingSent();
    }

    /* Helpers
     * - - - - - - - - - - - - - */

    private function driver(CertificateMode $mode = CertificateMode::DelegatedDns): CaddyIngressDriver
    {
        config()->set('tenant-domains.certificates.mode', $mode->value);

        return new CaddyIngressDriver(
            Platform::fromConfig(config('tenant-domains')),
            config('tenant-domains.drivers.ingresses.caddy'),
        );
    }

    /**
     * @param  array<string, mixed>  $tls
     */
    private function fakeCaddy(array $tls): void
    {
        Http::fake([
            '*/config/apps/tls' => Http::response($tls, 200),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function writtenConfig(): array
    {
        $written = null;

        Http::assertSent(function ($request) use (&$written) {
            if ($request->method() === 'PATCH') {
                $written = $request->data();

                return true;
            }

            return false;
        });

        $this->assertIsArray($written, 'Expected the driver to write a TLS config.');

        return $written;
    }
}
