<?php

namespace SocialDept\TenantDomains\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use SocialDept\TenantDomains\Core\DomainName;
use SocialDept\TenantDomains\Data\DomainRequirements;
use SocialDept\TenantDomains\Data\Instructions;
use SocialDept\TenantDomains\Domains;
use SocialDept\TenantDomains\Enums\RoutingMode;
use SocialDept\TenantDomains\Enums\WwwRedirect;
use SocialDept\TenantDomains\Tests\Fixtures\TestDomain;
use SocialDept\TenantDomains\Tests\TestCase;

/**
 * Serving a tenant's `www.` host is three separate decisions that have to agree,
 * and the failure when they disagree is silent in different ways each time: a
 * missing DNS record leaves the host unresolvable, a missing certificate subject
 * shows a TLS warning instead of a redirect, and a missing authorisation makes
 * the edge ask for a certificate it is then refused.
 *
 * The authorisation cases are security cases. `www.` is the one hostname the
 * package certifies without a row of its own.
 */
class WwwRedirectTest extends TestCase
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
            $table->string('routing_mode')->default('cname');
            $table->string('www_redirect')->nullable();
            $table->string('acme_delegation_id', 32)->nullable();
            $table->boolean('is_primary')->default(false);
        });
    }

    protected function tearDown(): void
    {
        Domains::forgetCertificateAuthorization();

        parent::tearDown();
    }

    /* The mode itself
     * - - - - - - - - - - - - - */

    #[Test]
    public function a_null_column_behaves_as_none(): void
    {
        $this->assertSame(WwwRedirect::None, WwwRedirect::fromColumn(null));
        $this->assertFalse(WwwRedirect::fromColumn(null)->servesWww());
    }

    /**
     * A value the enum does not know must not become a served host. A column
     * written by an older or newer release degrades to serving the apex alone.
     */
    #[Test]
    public function an_unrecognised_column_value_behaves_as_none(): void
    {
        $this->assertSame(WwwRedirect::None, WwwRedirect::fromColumn('sideways'));
    }

    #[Test]
    public function only_none_declines_to_serve_www(): void
    {
        $this->assertTrue(WwwRedirect::FromWww->servesWww());
        $this->assertTrue(WwwRedirect::ToWww->servesWww());
        $this->assertFalse(WwwRedirect::None->servesWww());
    }

    #[Test]
    public function the_address_is_www_only_when_pointing_at_it(): void
    {
        $apex = DomainName::make('example.com');

        $this->assertSame('example.com', WwwRedirect::FromWww->addressFor($apex));
        $this->assertSame('www.example.com', WwwRedirect::ToWww->addressFor($apex));
        $this->assertSame('example.com', WwwRedirect::None->addressFor($apex));
    }

    /**
     * A mode set on a row it cannot apply to is inert, not wrong. Nobody asks for
     * `www.blog.example.com`, and prefixing one would send visitors to a host the
     * tenant was never told to create.
     */
    #[Test]
    public function a_subdomain_is_never_given_a_www_prefix(): void
    {
        $this->assertSame(
            'blog.example.com',
            WwwRedirect::ToWww->addressFor(DomainName::make('blog.example.com')),
        );
    }

    #[Test]
    public function an_already_www_host_is_not_prefixed_twice(): void
    {
        $this->assertSame('www.example.com', DomainName::make('www.example.com')->www());
        $this->assertSame('www.example.com', DomainName::make('example.com')->www());
    }

    /**
     * `withoutWww()` strips only `www`, unlike `parent()`, which walks a label off
     * anything. The authorisation query depends on the difference.
     */
    #[Test]
    public function stripping_www_is_not_the_same_as_taking_the_parent(): void
    {
        $this->assertSame('example.com', DomainName::make('www.example.com')->withoutWww()->value);
        $this->assertSame('blog.example.com', DomainName::make('www.blog.example.com')->withoutWww()->value);
        $this->assertSame('blog.example.com', DomainName::make('blog.example.com')->withoutWww()->value);
    }

    /* DNS instructions
     * - - - - - - - - - - - - - */

    #[Test]
    public function serving_www_adds_a_cname_for_it(): void
    {
        $instructions = $this->build('example.com', wwwRedirect: WwwRedirect::FromWww);

        $www = $instructions->record('www');

        $this->assertNotNull($www);
        $this->assertSame('CNAME', $www->type);
        $this->assertSame('www', $www->name);
        $this->assertSame('www.example.com', $www->host);
        $this->assertSame('to.platform.test', $www->value);
    }

    /**
     * The apex may be on an A record while `www` is a CNAME. `www` is a subdomain,
     * so every provider can CNAME it, and pointing it at the name rather than the
     * address means it follows the apex if the ingress IP changes.
     */
    #[Test]
    public function the_www_record_stays_a_cname_even_when_the_apex_is_an_a_record(): void
    {
        $instructions = $this->build(
            'example.com',
            routingMode: RoutingMode::ARecord,
            wwwRedirect: WwwRedirect::ToWww,
        );

        $this->assertSame('A', $instructions->record('root')->type);
        $this->assertSame('CNAME', $instructions->record('www')->type);
        $this->assertSame('to.platform.test', $instructions->record('www')->value);
    }

    #[Test]
    public function declining_www_emits_no_record_for_it(): void
    {
        $this->assertNull($this->build('example.com', wwwRedirect: WwwRedirect::None)->record('www'));
        $this->assertNull($this->build('example.com')->record('www'));
    }

    #[Test]
    public function a_subdomain_is_never_given_a_www_record(): void
    {
        $instructions = $this->build('blog.example.com', wwwRedirect: WwwRedirect::ToWww);

        $this->assertNull($instructions->record('www'));
        $this->assertFalse($instructions->wwwIsChoosable());
    }

    /**
     * A domain that routes nothing is not given a www record either, or an
     * attached-but-dormant name would ask the tenant for DNS it does not need.
     */
    #[Test]
    public function a_domain_serving_nothing_is_given_no_www_record(): void
    {
        $instructions = $this->build(
            'example.com',
            requirements: DomainRequirements::none(),
            wwwRedirect: WwwRedirect::FromWww,
        );

        $this->assertNull($instructions->record('www'));
    }

    #[Test]
    public function the_instructions_report_the_address(): void
    {
        $this->assertSame('www.example.com', $this->build('example.com', wwwRedirect: WwwRedirect::ToWww)->address());
        $this->assertSame('example.com', $this->build('example.com', wwwRedirect: WwwRedirect::FromWww)->address());

        $array = $this->build('example.com', wwwRedirect: WwwRedirect::ToWww)->toArray();

        $this->assertSame('www.example.com', $array['address']);
        $this->assertSame('to_www', $array['wwwRedirect']);
        $this->assertTrue($array['wwwIsChoosable']);
    }

    /* Certificate authorisation
     * - - - - - - - - - - - - - */

    #[Test]
    public function the_www_host_is_authorised_by_an_apex_that_serves_it(): void
    {
        $this->domain('example.com', status: 'verified', wwwRedirect: 'from_www');

        $this->assertTrue($this->mayIssue('www.example.com'));
        $this->assertTrue($this->mayIssue('example.com'));
    }

    #[Test]
    public function the_www_host_is_authorised_when_it_is_the_canonical_address(): void
    {
        $this->domain('example.com', status: 'verified', wwwRedirect: 'to_www');

        $this->assertTrue($this->mayIssue('www.example.com'));
    }

    /**
     * The case that keeps the rate limit intact. Every apex on the platform has a
     * `www.` that resolves somewhere, and certifying them all unasked would spend
     * the weekly allowance on hosts nobody visits.
     */
    #[Test]
    public function the_www_host_is_refused_when_the_apex_has_not_opted_in(): void
    {
        $this->domain('example.com', status: 'verified', wwwRedirect: null);

        $this->assertFalse($this->mayIssue('www.example.com'));
        $this->assertTrue($this->mayIssue('example.com'));
    }

    #[Test]
    public function the_www_host_is_refused_when_the_apex_chose_none(): void
    {
        $this->domain('example.com', status: 'verified', wwwRedirect: 'none');

        $this->assertFalse($this->mayIssue('www.example.com'));
    }

    /**
     * Opting into www does not shortcut ownership. An unverified apex certifies
     * neither host.
     */
    #[Test]
    public function the_www_host_is_refused_while_the_apex_is_unverified(): void
    {
        $this->domain('example.com', status: 'pending', wwwRedirect: 'from_www');

        $this->assertFalse($this->mayIssue('www.example.com'));
        $this->assertFalse($this->mayIssue('example.com'));
    }

    #[Test]
    public function the_www_host_of_an_unknown_apex_is_refused(): void
    {
        $this->assertFalse($this->mayIssue('www.attacker.example'));
    }

    /**
     * `www.blog.example.com` must not be authorised by a `blog.example.com` row.
     * That row is a subdomain, was never offered the choice, and its column is
     * null, but the guard is the apex check rather than the column so a stray
     * value cannot open it.
     */
    #[Test]
    public function the_www_host_of_a_subdomain_is_refused(): void
    {
        $this->domain('blog.example.com', status: 'verified', wwwRedirect: 'from_www');

        $this->assertFalse($this->mayIssue('www.blog.example.com'));
    }

    /**
     * A tenant who attached `www.example.com` as a domain in its own right is
     * authorised by that row, with no apex involved.
     */
    #[Test]
    public function a_www_host_attached_as_its_own_domain_is_authorised_on_its_own(): void
    {
        $this->domain('www.example.com', status: 'verified');

        $this->assertTrue($this->mayIssue('www.example.com'));
        $this->assertFalse($this->mayIssue('example.com'));
    }

    /* The edge binding
     * - - - - - - - - - - - - - */

    #[Test]
    public function the_binding_includes_the_www_host_so_the_certificate_covers_it(): void
    {
        $binding = app(Domains::class)->bindingFor($this->model('example.com', 'from_www'));

        $this->assertSame(['example.com', 'www.example.com'], $binding->hostnames);
        $this->assertFalse($binding->isWildcard());
    }

    #[Test]
    public function the_binding_omits_the_www_host_when_it_is_not_served(): void
    {
        $this->assertSame(
            ['example.com'],
            app(Domains::class)->bindingFor($this->model('example.com', null))->hostnames,
        );

        $this->assertSame(
            ['example.com'],
            app(Domains::class)->bindingFor($this->model('example.com', 'none'))->hostnames,
        );
    }

    #[Test]
    public function the_binding_omits_the_www_host_for_a_subdomain(): void
    {
        $this->assertSame(
            ['blog.example.com'],
            app(Domains::class)->bindingFor($this->model('blog.example.com', 'to_www'))->hostnames,
        );
    }

    /* The model's accessors
     * - - - - - - - - - - - - - */

    #[Test]
    public function the_model_reports_the_www_host_and_whether_it_is_served(): void
    {
        $serving = $this->model('example.com', 'from_www');

        $this->assertSame('www.example.com', $serving->www_host);
        $this->assertTrue($serving->serves_www);

        $declining = $this->model('other.com', null);

        $this->assertSame('www.other.com', $declining->www_host);
        $this->assertFalse($declining->serves_www);
    }

    /**
     * `hostname` stays the domain's identity in DNS even when visitors are sent to
     * `www.`. Every record the package asks for is computed relative to it, so an
     * ownership TXT belongs at `_verify.example.com` and an apex A record at `@`
     * whichever host is canonical.
     */
    #[Test]
    public function the_address_is_separate_from_the_hostname(): void
    {
        $toWww = $this->model('example.com', 'to_www');

        $this->assertSame('example.com', $toWww->hostname);
        $this->assertSame('www.example.com', $toWww->address);

        $fromWww = $this->model('other.com', 'from_www');

        $this->assertSame('other.com', $fromWww->hostname);
        $this->assertSame('other.com', $fromWww->address);
    }

    #[Test]
    public function a_subdomain_has_no_www_host_and_serves_none(): void
    {
        $row = $this->model('blog.example.com', 'to_www');

        $this->assertNull($row->www_host);
        $this->assertFalse($row->serves_www);
        $this->assertSame('blog.example.com', $row->address);
    }

    #[Test]
    public function a_platform_subdomain_has_no_www_host(): void
    {
        $row = $this->model('acme', 'to_www', platformBase: 'platform.test');

        $this->assertNull($row->www_host);
        $this->assertFalse($row->serves_www);
        $this->assertSame('acme.platform.test', $row->address);
    }

    /**
     * Changing the column has to change what the row answers.
     *
     * Eloquent caches an accessor that returns an object, an enum included, and
     * nothing invalidates that cache when the underlying column is written. Without
     * `withoutObjectCaching()` the mode, the address and `serves_www` all keep their
     * pre-update values, so a saved choice reads back as a save that did nothing.
     */
    #[Test]
    public function updating_the_column_changes_the_address_on_the_same_instance(): void
    {
        $row = $this->model('example.com', null);

        $this->assertSame('example.com', $row->address);
        $this->assertFalse($row->serves_www);

        $row->update(['www_redirect' => WwwRedirect::ToWww]);

        $this->assertSame(WwwRedirect::ToWww, $row->www_mode);
        $this->assertSame('www.example.com', $row->address);
        $this->assertTrue($row->serves_www);
    }

    #[Test]
    public function renaming_the_domain_changes_the_parsed_host_on_the_same_instance(): void
    {
        $row = $this->model('example.com', 'to_www');

        $this->assertSame('example.com', $row->parsed_host->value);

        $row->update(['domain' => 'other.test']);

        $this->assertSame('other.test', $row->parsed_host->value);
        $this->assertSame('www.other.test', $row->address);
    }

    /* The serving-host scope
     * - - - - - - - - - - - - - */

    #[Test]
    public function the_scope_finds_the_apex_that_serves_a_www_host(): void
    {
        $this->model('example.com', 'from_www');

        $this->assertSame(
            'example.com',
            TestDomain::query()->servingHost('www.example.com')->value('domain'),
        );
    }

    #[Test]
    public function the_scope_ignores_an_apex_that_declined_www(): void
    {
        $this->model('example.com', null);
        $this->model('other.com', 'none');

        $this->assertSame(0, TestDomain::query()->servingHost('www.example.com')->count());
        $this->assertSame(0, TestDomain::query()->servingHost('www.other.com')->count());
    }

    /**
     * The shadowing case. A tenant who attached `www.example.com` outright must win
     * over a different tenant's apex that merely serves its alias, or one
     * publication answers on the other's hostname.
     */
    #[Test]
    public function an_exact_row_takes_precedence_over_an_apex_alias(): void
    {
        $apex = $this->model('example.com', 'from_www');
        $exact = $this->model('www.example.com', null);

        $matches = TestDomain::query()->servingHost('www.example.com')->pluck('id');

        $this->assertCount(2, $matches);

        $this->assertSame(
            $exact->id,
            TestDomain::query()->servingHostByPrecedence('www.example.com')->first()->id,
        );

        $this->assertNotSame($apex->id, TestDomain::query()->servingHostByPrecedence('www.example.com')->first()->id);
    }

    #[Test]
    public function the_scope_does_not_treat_a_subdomains_www_as_an_alias(): void
    {
        $this->model('blog.example.com', 'from_www');

        $this->assertSame(0, TestDomain::query()->servingHost('www.blog.example.com')->count());
    }

    #[Test]
    public function a_platform_row_never_serves_a_www_alias(): void
    {
        $this->model('acme', 'from_www', platformBase: 'platform.test');

        $this->assertSame(0, TestDomain::query()->servingHost('www.acme')->count());
    }

    /* Helpers
     * - - - - - - - - - - - - - */

    private function build(
        string $host,
        ?DomainRequirements $requirements = null,
        ?RoutingMode $routingMode = null,
        WwwRedirect $wwwRedirect = WwwRedirect::None,
    ): Instructions {
        return Instructions::build(
            domain: DomainName::make($host),
            platform: $this->platform(),
            requirements: $requirements ?? DomainRequirements::default(),
            routingMode: $routingMode ?? RoutingMode::Cname,
            ownershipToken: 'tok_123',
            delegationId: 'abc123',
            wwwRedirect: $wwwRedirect,
        );
    }

    private function mayIssue(string $host): bool
    {
        return app(Domains::class)->mayIssueCertificateFor(DomainName::make($host));
    }

    private function domain(string $domain, string $status = 'pending', ?string $wwwRedirect = null): void
    {
        DB::table('domains')->insert([
            'domain' => $domain,
            'platform_base' => null,
            'status' => $status,
            'www_redirect' => $wwwRedirect,
        ]);
    }

    private function model(string $domain, ?string $wwwRedirect, ?string $platformBase = null): TestDomain
    {
        return TestDomain::create([
            'domain' => $domain,
            'platform_base' => $platformBase,
            'status' => 'verified',
            'www_redirect' => $wwwRedirect,
        ]);
    }
}
