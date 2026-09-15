<?php

namespace SocialDept\TenantDomains\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use SocialDept\TenantDomains\Core\DomainName;
use SocialDept\TenantDomains\Domains;
use SocialDept\TenantDomains\Tests\TestCase;

/**
 * The ask endpoint is the only thing between a port scanner and the Let's
 * Encrypt rate limit for the whole platform, so every case here is a security
 * case, not a convenience one.
 */
class CertificateAuthorisationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Domains::forgetCertificateAuthorization();

        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->string('domain');
            $table->string('platform_base')->nullable();
            $table->string('status')->default('pending');
        });
    }

    protected function tearDown(): void
    {
        Domains::forgetCertificateAuthorization();

        parent::tearDown();
    }

    #[Test]
    public function a_verified_custom_domain_is_authorised(): void
    {
        $this->domain('example.com', status: 'verified');

        $this->assertTrue($this->mayIssue('example.com'));
    }

    /**
     * Confirming is routable on purpose: a domain has to be served and hold a
     * certificate before a real request to it can prove it is reachable.
     */
    #[Test]
    public function a_confirming_domain_is_authorised_so_it_can_be_probed(): void
    {
        $this->domain('example.com', status: 'confirming');

        $this->assertTrue($this->mayIssue('example.com'));
    }

    #[Test]
    public function a_pending_domain_is_refused(): void
    {
        $this->domain('example.com', status: 'pending');

        $this->assertFalse($this->mayIssue('example.com'));
    }

    #[Test]
    public function an_unknown_domain_is_refused(): void
    {
        $this->assertFalse($this->mayIssue('attacker.example'));
    }

    /**
     * Platform subdomains are stored as bare labels and are always in service,
     * because their DNS is ours and there is nothing for a tenant to prove.
     */
    #[Test]
    public function a_platform_subdomain_is_authorised_by_its_label(): void
    {
        $this->domain('acme', platformBase: 'platform.test', status: 'pending');

        $this->assertTrue($this->mayIssue('acme.platform.test'));
    }

    /**
     * The regression that matters. A top-level `orWhereNotNull('platform_base')`
     * matches any row with a platform base, which authorises every hostname on
     * the internet as soon as one platform subdomain exists.
     */
    #[Test]
    public function an_unrelated_host_is_refused_even_when_platform_subdomains_exist(): void
    {
        $this->domain('acme', platformBase: 'platform.test');
        $this->domain('example.com', status: 'verified');

        $this->assertFalse($this->mayIssue('attacker.example'));
        $this->assertFalse($this->mayIssue('random.evil.test'));
    }

    /**
     * A deep junk subdomain must never resolve back to a tenant's row. Scanners
     * walking `*.platform.test` are what exhausted one platform's weekly
     * certificate allowance.
     */
    #[Test]
    public function a_deep_subdomain_of_the_platform_is_refused(): void
    {
        $this->domain('acme', platformBase: 'platform.test');

        $this->assertFalse($this->mayIssue('junk.acme.platform.test'));
        $this->assertFalse($this->mayIssue('a.b.platform.test'));
    }

    #[Test]
    public function an_unregistered_platform_subdomain_is_refused(): void
    {
        $this->assertFalse($this->mayIssue('nobody.platform.test'));
    }

    /**
     * A custom domain row must not be matched by a same-named platform label,
     * or a tenant could claim `acme.platform.test` by registering the custom
     * domain `acme`.
     */
    #[Test]
    public function a_custom_domain_row_does_not_authorise_a_platform_host(): void
    {
        $this->domain('acme', platformBase: null, status: 'verified');

        $this->assertFalse($this->mayIssue('acme.platform.test'));
    }

    #[Test]
    public function a_host_app_can_widen_authorisation_for_per_account_hostnames(): void
    {
        $this->domain('example.com', status: 'verified');

        Domains::authorizeCertificatesUsing(
            fn (DomainName $host): bool => str_ends_with($host->value, '.example.com'),
        );

        $this->assertTrue($this->mayIssue('alice.example.com'));
        $this->assertFalse($this->mayIssue('example.com'));
    }

    private function mayIssue(string $host): bool
    {
        return app(Domains::class)->mayIssueCertificateFor(DomainName::make($host));
    }

    private function domain(string $domain, ?string $platformBase = null, string $status = 'pending'): void
    {
        \DB::table('domains')->insert([
            'domain' => $domain,
            'platform_base' => $platformBase,
            'status' => $status,
        ]);
    }
}
