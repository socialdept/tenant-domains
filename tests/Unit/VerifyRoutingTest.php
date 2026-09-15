<?php

namespace SocialDept\TenantDomains\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SocialDept\TenantDomains\Actions\VerifyRouting;
use SocialDept\TenantDomains\Core\Detection\ProxyDetector;
use SocialDept\TenantDomains\Dns\ArrayResolver;
use SocialDept\TenantDomains\Exceptions\DnsUnavailable;
use SocialDept\TenantDomains\Tests\TestCase;

class VerifyRoutingTest extends TestCase
{
    #[Test]
    public function a_literal_cname_to_our_target_verifies(): void
    {
        $result = $this->verify('blog.example.com', new ArrayResolver([
            'blog.example.com' => ['CNAME' => 'to.platform.test'],
        ]));

        $this->assertTrue($result->verified);
        $this->assertSame('cname', $result->method);
    }

    #[Test]
    public function a_trailing_dot_and_mixed_case_still_match(): void
    {
        $result = $this->verify('blog.example.com', new ArrayResolver([
            'blog.example.com' => ['CNAME' => 'TO.Platform.Test.'],
        ]));

        $this->assertTrue($result->verified);
    }

    /**
     * Apex domains, and any provider that flattens a CNAME into A records before
     * anyone outside can see it.
     */
    #[Test]
    public function an_a_record_on_a_configured_ingress_address_verifies(): void
    {
        $result = $this->verify('example.com', new ArrayResolver([
            'example.com' => ['A' => '203.0.113.10'],
        ]));

        $this->assertTrue($result->verified);
        $this->assertSame('a_record', $result->method);
    }

    /**
     * The bug a single configured IP creates: a platform running a passthrough
     * edge in front of the origin has two legitimate answers, and comparing
     * against one reports its own edge as a misconfiguration.
     */
    #[Test]
    public function an_a_record_on_the_live_cname_target_verifies_too(): void
    {
        // The configured list knows only the edge. The origin is whatever
        // `to.platform.test` resolves to right now.
        config()->set('tenant-domains.routing.ingress_ips', ['198.51.100.7']);

        $result = $this->verify('example.com', new ArrayResolver([
            'example.com' => ['A' => '203.0.113.10'],
            'to.platform.test' => ['A' => '203.0.113.10'],
        ]));

        $this->assertTrue($result->verified);
        $this->assertSame('a_record', $result->method);
    }

    #[Test]
    public function a_domain_pointing_somewhere_else_fails_and_says_where(): void
    {
        $result = $this->verify('example.com', new ArrayResolver([
            'example.com' => ['A' => '192.0.2.99'],
        ]));

        $this->assertFalse($result->verified);
        $this->assertTrue($result->resolvesElsewhere());
        $this->assertSame('192.0.2.99', $result->resolved);
        $this->assertNull($result->hint);
    }

    #[Test]
    public function a_domain_with_no_records_at_all_fails_without_a_resolved_address(): void
    {
        $result = $this->verify('example.com', new ArrayResolver());

        $this->assertFalse($result->verified);
        $this->assertFalse($result->resolvesElsewhere());
        $this->assertNull($result->resolved);
    }

    /**
     * A proxied record answers with the proxy's address, so we genuinely cannot
     * see where it points. That is not the same as wrong, and the tenant is told
     * which it is.
     */
    #[Test]
    public function a_cloudflare_proxied_domain_gets_a_hint_rather_than_a_flat_failure(): void
    {
        $result = $this->verify('example.com', new ArrayResolver([
            'example.com' => ['A' => '104.16.0.1'],
        ]));

        $this->assertFalse($result->verified);
        $this->assertNotNull($result->hint);
        $this->assertStringContainsString('proxied through Cloudflare', $result->hint);
    }

    /**
     * A resolver outage must not be reported as the tenant's missing record.
     */
    #[Test]
    public function a_resolver_outage_throws_rather_than_failing_the_domain(): void
    {
        $resolver = (new ArrayResolver())->fail('example.com');

        $this->expectException(DnsUnavailable::class);

        $this->verify('example.com', $resolver);
    }

    /**
     * Our own CNAME target failing to resolve must not fail the tenant's check:
     * the configured ingress list still stands on its own.
     */
    #[Test]
    public function an_unresolvable_cname_target_falls_back_to_the_configured_list(): void
    {
        $resolver = (new ArrayResolver([
            'example.com' => ['A' => '203.0.113.10'],
        ]))->fail('to.platform.test');

        $this->assertTrue($this->verify('example.com', $resolver)->verified);
    }

    private function verify(string $host, ArrayResolver $resolver): \SocialDept\TenantDomains\Data\RoutingResult
    {
        $action = new VerifyRouting($resolver, $this->platform(), new ProxyDetector($resolver));

        return $action($host);
    }
}
