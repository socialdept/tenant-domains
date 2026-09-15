<?php

namespace SocialDept\TenantDomains\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use SocialDept\TenantDomains\Tests\TestCase;

class AuthorityEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->string('domain');
            $table->string('platform_base')->nullable();
            $table->string('status')->default('pending');
        });
    }

    #[Test]
    public function it_answers_on_the_default_path(): void
    {
        $this->domain('example.com', 'verified');

        $this->get('api/caddy/verify?domain=example.com')->assertOk();
    }

    #[Test]
    public function an_unknown_host_is_refused(): void
    {
        $this->get('api/caddy/verify?domain=attacker.example')->assertNotFound();
    }

    #[Test]
    public function a_missing_domain_parameter_is_a_bad_request(): void
    {
        $this->get('api/caddy/verify')->assertStatus(400);
    }

    #[Test]
    public function a_malformed_hostname_is_a_bad_request(): void
    {
        $this->get('api/caddy/verify?domain=not-a-hostname')->assertStatus(400);
    }

    /**
     * Not everyone wants a route called `api/caddy/verify`. The path only has to
     * agree with whatever the edge is configured to call.
     */
    #[Test]
    public function the_path_is_configurable(): void
    {
        $this->domain('example.com', 'verified');

        $this->get('internal/tls/authorize?domain=example.com')->assertOk();
    }

    /**
     * An app that wants the route under its own conventions, on its own router,
     * or behind its own middleware registers it itself.
     */
    #[Test]
    public function registration_can_be_turned_off_entirely(): void
    {
        $this->get('nowhere/at/all?domain=example.com')->assertNotFound();
    }

    protected function defineEnvironment($app): void
    {
        $test = $this->name();

        if ($test === 'the_path_is_configurable') {
            $app['config']->set('tenant-domains.authority_endpoint.path', 'internal/tls/authorize');
        }

        if ($test === 'registration_can_be_turned_off_entirely') {
            $app['config']->set('tenant-domains.authority_endpoint.enabled', false);
        }
    }

    private function domain(string $domain, string $status): void
    {
        \DB::table('domains')->insert([
            'domain' => $domain,
            'platform_base' => null,
            'status' => $status,
        ]);
    }
}
