<?php

namespace SocialDept\TenantDomains\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use SocialDept\TenantDomains\Actions\ConfirmReachable;
use SocialDept\TenantDomains\Actions\ReconcileDomain;
use SocialDept\TenantDomains\Actions\VerifyOwnership;
use SocialDept\TenantDomains\Actions\VerifyRouting;
use SocialDept\TenantDomains\Contracts\IngressDriver;
use SocialDept\TenantDomains\Contracts\ProvidesOwnershipToken;
use SocialDept\TenantDomains\Core\Detection\ProviderDetector;
use SocialDept\TenantDomains\Core\Detection\ProxyDetector;
use SocialDept\TenantDomains\Dns\ArrayResolver;
use SocialDept\TenantDomains\Domains;
use SocialDept\TenantDomains\Enums\DomainStatus;
use SocialDept\TenantDomains\Enums\ReconcileOutcome;
use SocialDept\TenantDomains\Events\DomainConfirming;
use SocialDept\TenantDomains\Events\DomainFailed;
use SocialDept\TenantDomains\Events\DomainVerified;
use SocialDept\TenantDomains\Events\OwnershipProven;
use SocialDept\TenantDomains\Models\Domain;
use SocialDept\TenantDomains\Testing\FakeIngressDriver;
use SocialDept\TenantDomains\Tests\TestCase;

class ReconcileDomainTest extends TestCase
{
    private FakeIngressDriver $ingress;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->string('domain');
            $table->string('platform_base')->nullable();
            $table->string('status')->default('pending');
            $table->string('routing_mode')->default('cname');
            $table->string('acme_delegation_id')->nullable();
            $table->timestamp('ownership_verified_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_failure_reason')->nullable();
            $table->timestamps();
        });

        $this->ingress = new FakeIngressDriver();
        $this->app->instance(IngressDriver::class, $this->ingress);
    }

    /* The happy path, one step per pass
     * - - - - - - - - - - - - - */

    #[Test]
    public function the_first_pass_proves_ownership_and_stops_there(): void
    {
        Event::fake();
        $domain = $this->domain();

        $result = $this->reconcile($domain, $this->fullyConfiguredZone());

        $this->assertSame(ReconcileOutcome::Advanced, $result->outcome);
        $this->assertNotNull($domain->fresh()->ownership_verified_at);
        $this->assertSame(DomainStatus::Pending, $domain->fresh()->status);

        Event::assertDispatched(OwnershipProven::class);
    }

    /**
     * A domain is routed and then left to get its certificate. Probing over
     * HTTPS in the same pass would fail for a reason that has nothing to do with
     * the tenant.
     */
    #[Test]
    public function the_second_pass_routes_and_waits_for_a_certificate(): void
    {
        Event::fake();
        $domain = $this->domain(ownershipProven: true);

        $result = $this->reconcile($domain, $this->fullyConfiguredZone());

        $this->assertSame(ReconcileOutcome::Advanced, $result->outcome);
        $this->assertSame(DomainStatus::Confirming, $domain->fresh()->status);
        $this->ingress->assertPublished('blog.example.com');

        Event::assertDispatched(DomainConfirming::class);
        Event::assertNotDispatched(DomainVerified::class);
    }

    #[Test]
    public function the_third_pass_confirms_reachability_and_verifies(): void
    {
        Event::fake();
        Http::fake(['https://blog.example.com/' => Http::response('ok', 200)]);

        $domain = $this->domain(ownershipProven: true, status: DomainStatus::Confirming);

        $result = $this->reconcile($domain, $this->fullyConfiguredZone());

        $this->assertSame(ReconcileOutcome::Verified, $result->outcome);
        $this->assertSame(DomainStatus::Verified, $domain->fresh()->status);
        $this->assertNotNull($domain->fresh()->verified_at);

        Event::assertDispatched(DomainVerified::class);
    }

    /* The cases that cause outages
     * - - - - - - - - - - - - - */

    /**
     * The single most important behaviour here. A resolver blip must not mark a
     * whole table as failing, and must leave every status untouched.
     */
    #[Test]
    public function a_resolver_outage_leaves_the_domain_exactly_as_it_was(): void
    {
        Event::fake();
        $domain = $this->domain();

        $resolver = $this->fullyConfiguredZone()->fail('_verify.blog.example.com');

        $result = $this->reconcile($domain, $resolver);

        $this->assertSame(ReconcileOutcome::Unavailable, $result->outcome);
        $this->assertSame(DomainStatus::Pending, $domain->fresh()->status);
        $this->assertNull($domain->fresh()->ownership_verified_at);
        $this->assertNull($domain->fresh()->last_failure_reason);

        // Above all: never blamed on the tenant.
        Event::assertNotDispatched(DomainFailed::class);
    }

    /**
     * Running every five minutes, an event per pass is an event forever. One
     * broken record must not become a notification every five minutes.
     */
    #[Test]
    public function an_unchanged_failure_reason_does_not_re_fire_the_event(): void
    {
        Event::fake();
        $domain = $this->domain(ownershipProven: true);

        $misconfigured = new ArrayResolver(['blog.example.com' => ['A' => '192.0.2.99']]);

        $this->reconcile($domain, $misconfigured);
        Event::assertDispatchedTimes(DomainFailed::class, 1);

        $this->reconcile($domain->fresh(), $misconfigured);
        $this->reconcile($domain->fresh(), $misconfigured);

        Event::assertDispatchedTimes(DomainFailed::class, 1);
    }

    #[Test]
    public function a_changed_failure_reason_does_fire_again(): void
    {
        Event::fake();
        $domain = $this->domain(ownershipProven: true);

        $this->reconcile($domain, new ArrayResolver(['blog.example.com' => ['A' => '192.0.2.99']]));
        $this->reconcile($domain->fresh(), new ArrayResolver());

        Event::assertDispatchedTimes(DomainFailed::class, 2);
    }

    /**
     * Nothing reaches the edge until the domain actually points at us. A domain
     * that does not has no business holding our certificate.
     */
    #[Test]
    public function a_domain_that_does_not_point_at_us_is_never_published(): void
    {
        $domain = $this->domain(ownershipProven: true);

        $result = $this->reconcile($domain, new ArrayResolver(['blog.example.com' => ['A' => '192.0.2.99']]));

        $this->assertSame(ReconcileOutcome::Waiting, $result->outcome);
        $this->ingress->assertNothingPublished();
        $this->assertSame(DomainStatus::Pending, $domain->fresh()->status);
    }

    /**
     * A tenant's leftover redirect rule passes every DNS check. The domain stays
     * Confirming rather than reverting. Their records are right, and only the
     * interception has to go.
     */
    #[Test]
    public function a_redirect_keeps_the_domain_confirming_and_says_why(): void
    {
        Event::fake();
        Http::fake([
            'https://blog.example.com/' => Http::response('', 302, ['Location' => 'https://old-host.example']),
        ]);

        $domain = $this->domain(ownershipProven: true, status: DomainStatus::Confirming);

        $result = $this->reconcile($domain, $this->fullyConfiguredZone());

        $this->assertSame(ReconcileOutcome::Waiting, $result->outcome);
        $this->assertSame(DomainStatus::Confirming, $domain->fresh()->status);
        $this->assertStringContainsString('old-host.example', $domain->fresh()->last_failure_reason);

        Event::assertNotDispatched(DomainVerified::class);
    }

    /**
     * A proxied domain cannot be proven by DNS, so the probe decides. Before
     * this, ownership was proven and then routing could never pass, which left
     * every orange-clouded customer stuck at setup with advice that led nowhere.
     */
    #[Test]
    public function a_proxied_domain_is_carried_to_the_probe_rather_than_stalled(): void
    {
        Event::fake();
        $domain = $this->domain(ownershipProven: true);

        $proxied = new ArrayResolver(['blog.example.com' => ['A' => '104.16.0.1']]);

        $result = $this->reconcile($domain, $proxied);

        $this->assertSame(ReconcileOutcome::Advanced, $result->outcome);
        $this->assertSame(DomainStatus::Confirming, $domain->fresh()->status);
        $this->ingress->assertPublished('blog.example.com');
    }

    /**
     * With no probe there is nothing left that could prove a proxied domain
     * points at us, and ownership alone must never put one in service.
     */
    #[Test]
    public function a_proxied_domain_is_refused_when_the_probe_is_disabled(): void
    {
        config()->set('tenant-domains.reachability.enabled', false);

        $domain = $this->domain(ownershipProven: true);

        $result = $this->reconcile($domain, new ArrayResolver(['blog.example.com' => ['A' => '104.16.0.1']]));

        $this->assertSame(ReconcileOutcome::Waiting, $result->outcome);
        $this->assertSame(DomainStatus::Pending, $domain->fresh()->status);
        $this->ingress->assertNothingPublished();
        $this->assertStringContainsString('DNS only', $domain->fresh()->last_failure_reason);
    }

    /* Idempotency
     * - - - - - - - - - - - - - */

    #[Test]
    public function a_verified_domain_is_skipped_entirely(): void
    {
        Event::fake();
        $domain = $this->domain(ownershipProven: true, status: DomainStatus::Verified);

        $result = $this->reconcile($domain, new ArrayResolver());

        $this->assertSame(ReconcileOutcome::Skipped, $result->outcome);
        $this->ingress->assertNothingPublished();
        $this->assertNoLifecycleEvents();
    }

    #[Test]
    public function ownership_is_not_re_checked_once_proven(): void
    {
        $domain = $this->domain(ownershipProven: true);

        // No ownership record in this zone at all. If it were re-checked, the
        // domain would stall. A tenant is entitled to delete the TXT record
        // once their domain is live.
        $resolver = new ArrayResolver([
            'blog.example.com' => ['CNAME' => 'to.platform.test'],
        ]);

        $result = $this->reconcile($domain, $resolver);

        $this->assertSame(ReconcileOutcome::Advanced, $result->outcome);
        $this->assertSame(DomainStatus::Confirming, $domain->fresh()->status);
    }

    #[Test]
    public function a_dry_run_reports_without_writing_or_publishing(): void
    {
        Event::fake();
        $domain = $this->domain();

        $result = $this->reconcile($domain, $this->fullyConfiguredZone(), dryRun: true);

        $this->assertSame(ReconcileOutcome::Advanced, $result->outcome);
        $this->assertNull($domain->fresh()->ownership_verified_at);
        $this->ingress->assertNothingPublished();
        $this->assertNoLifecycleEvents();
    }

    #[Test]
    public function reachability_can_be_turned_off_for_apps_that_cannot_probe_themselves(): void
    {
        config()->set('tenant-domains.reachability.enabled', false);
        Http::fake();

        $domain = $this->domain(ownershipProven: true, status: DomainStatus::Confirming);

        $result = $this->reconcile($domain, $this->fullyConfiguredZone());

        $this->assertSame(ReconcileOutcome::Verified, $result->outcome);
        Http::assertNothingSent();
    }

    /* The command
     * - - - - - - - - - - - - - */

    #[Test]
    public function the_command_skips_domains_checked_moments_ago(): void
    {
        $this->bindDomains($this->fullyConfiguredZone());

        $this->domain()->forceFill(['last_checked_at' => now()->subSeconds(5)])->save();

        $this->artisan('domains:reconcile')
            ->expectsOutputToContain('Nothing to reconcile.')
            ->assertSuccessful();
    }

    #[Test]
    public function the_command_ignores_the_throttle_for_an_explicit_domain(): void
    {
        $this->bindDomains($this->fullyConfiguredZone());

        $this->domain()->forceFill(['last_checked_at' => now()])->save();

        $this->artisan('domains:reconcile', ['--domain' => 'blog.example.com'])
            ->expectsOutputToContain('ownership proven')
            ->assertSuccessful();
    }

    #[Test]
    public function the_command_never_sweeps_platform_subdomains(): void
    {
        $this->bindDomains($this->fullyConfiguredZone());

        Domain::create(['domain' => 'acme', 'platform_base' => 'platform.test', 'status' => 'pending']);

        $this->artisan('domains:reconcile')
            ->expectsOutputToContain('Nothing to reconcile.')
            ->assertSuccessful();
    }

    /**
     * A pass where every lookup failed is our outage, not a batch of tenants who
     * all got it wrong on the same tick, so it must be visible to a monitor.
     */
    #[Test]
    public function the_command_fails_when_dns_is_unavailable_for_everything(): void
    {
        $this->bindDomains((new ArrayResolver())->fail('_verify.blog.example.com'));

        $this->domain();

        $this->artisan('domains:reconcile')->assertFailed();
    }

    /* Helpers
     * - - - - - - - - - - - - - */

    /**
     * None of the package's own events fired. Scoped deliberately: Eloquent
     * dispatches its own model events, so a blanket assertion would never pass.
     */
    private function assertNoLifecycleEvents(): void
    {
        foreach ([OwnershipProven::class, DomainConfirming::class, DomainVerified::class, DomainFailed::class] as $event) {
            Event::assertNotDispatched($event);
        }
    }

    private function fullyConfiguredZone(): ArrayResolver
    {
        return new ArrayResolver([
            'blog.example.com' => ['CNAME' => 'to.platform.test'],
            '_verify.blog.example.com' => ['TXT' => 'verification=tok_123'],
        ]);
    }

    private function domain(
        bool $ownershipProven = false,
        DomainStatus $status = DomainStatus::Pending,
    ): Domain {
        return DomainStub::create([
            'domain' => 'blog.example.com',
            'status' => $status->value,
            'acme_delegation_id' => 'abc123',
            'ownership_verified_at' => $ownershipProven ? now() : null,
        ]);
    }

    private function reconcile($domain, ArrayResolver $resolver, bool $dryRun = false)
    {
        $action = new ReconcileDomain($this->makeDomains($resolver), new ConfirmReachable());

        return $action($domain, $dryRun);
    }

    private function bindDomains(ArrayResolver $resolver): void
    {
        config()->set('tenant-domains.model', DomainStub::class);
        $this->app->instance(Domains::class, $this->makeDomains($resolver));
    }

    private function makeDomains(ArrayResolver $resolver): Domains
    {
        $platform = $this->platform();

        return new Domains(
            $platform,
            new ProviderDetector($resolver),
            new VerifyOwnership($resolver, $platform),
            new VerifyRouting($resolver, $platform, new ProxyDetector($resolver)),
            $this->ingress,
        );
    }
}

/**
 * A domain whose tenant supplies the ownership token directly, so these tests
 * need no tenant table.
 */
class DomainStub extends Domain implements ProvidesOwnershipToken
{
    protected $table = 'domains';

    public function domainOwnershipToken(): string
    {
        return 'tok_123';
    }
}
