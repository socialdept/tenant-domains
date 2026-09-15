<?php

namespace SocialDept\TenantDomains\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SocialDept\TenantDomains\Core\Detection\ProviderDetector;
use SocialDept\TenantDomains\Dns\ArrayResolver;
use SocialDept\TenantDomains\Enums\RoutingMode;
use SocialDept\TenantDomains\Tests\TestCase;

class ProviderDetectorTest extends TestCase
{
    #[Test]
    public function it_identifies_a_provider_from_its_nameservers(): void
    {
        $detection = $this->detector([
            'example.com' => ['NS' => ['gwen.ns.cloudflare.com', 'rick.ns.cloudflare.com']],
        ])->detect('example.com');

        $this->assertSame('Cloudflare', $detection['provider']);
        $this->assertTrue($detection['is_cloudflare']);
        $this->assertTrue($detection['supports_apex_cname']);
    }

    /**
     * Several keys are infixes rather than suffixes. `azure-dns` sits in the middle
     * of `ns1-01.azure-dns.com`.
     */
    #[Test]
    public function it_matches_providers_whose_key_is_an_infix(): void
    {
        $detection = $this->detector([
            'example.com' => ['NS' => ['ns1-01.azure-dns.com', 'ns2-01.azure-dns.net']],
        ])->detect('example.com');

        $this->assertSame('Azure DNS', $detection['provider']);
        $this->assertFalse($detection['supports_apex_cname']);
    }

    /**
     * Unknown is not the same as unsupported, and the null is what lets the
     * caller pick the universally safe answer rather than guessing.
     */
    #[Test]
    public function an_unrecognised_provider_reports_unknown_not_unsupported(): void
    {
        $detection = $this->detector([
            'example.com' => ['NS' => ['ns1.some-tiny-registrar.example']],
        ])->detect('example.com');

        $this->assertNull($detection['provider']);
        $this->assertNull($detection['supports_apex_cname']);
        $this->assertFalse($detection['is_cloudflare']);
    }

    /**
     * A subdomain rarely has NS records of its own. The delegation lives on the
     * registrable domain. Asking for the host's own NS records returns nothing
     * and makes every subdomain look unrecognised.
     */
    #[Test]
    public function it_looks_up_nameservers_on_the_registrable_domain(): void
    {
        $detection = $this->detector([
            'example.co.uk' => ['NS' => ['gwen.ns.cloudflare.com']],
        ])->detect('blog.example.co.uk');

        $this->assertSame('Cloudflare', $detection['provider']);
    }

    #[Test]
    public function subdomains_always_get_a_cname_whatever_the_provider(): void
    {
        $mode = $this->detector([
            'example.com' => ['NS' => ['ns1.domaincontrol.com']],
        ])->recommendRoutingMode('blog.example.com');

        $this->assertSame(RoutingMode::Cname, $mode);
    }

    #[Test]
    public function an_apex_on_a_flattening_provider_is_recommended_a_cname(): void
    {
        $mode = $this->detector([
            'example.com' => ['NS' => ['gwen.ns.cloudflare.com']],
        ])->recommendRoutingMode('example.com');

        $this->assertSame(RoutingMode::Cname, $mode);
    }

    #[Test]
    public function an_apex_on_a_non_flattening_provider_is_recommended_an_a_record(): void
    {
        $mode = $this->detector([
            'example.com' => ['NS' => ['ns1.wixdns.net']],
        ])->recommendRoutingMode('example.com');

        $this->assertSame(RoutingMode::ARecord, $mode);
    }

    /**
     * The safe default. An apex CNAME only works where the provider offers
     * flattening, so an A record, which works everywhere, is the right guess when we
     * do not recognise the provider.
     */
    #[Test]
    public function an_apex_on_an_unknown_provider_is_recommended_an_a_record(): void
    {
        $mode = $this->detector([
            'example.com' => ['NS' => ['ns1.unknown.example']],
        ])->recommendRoutingMode('example.com');

        $this->assertSame(RoutingMode::ARecord, $mode);
    }

    #[Test]
    public function the_nameserver_report_keeps_the_raw_records(): void
    {
        $report = $this->detector([
            'example.com' => ['NS' => ['gwen.ns.cloudflare.com', 'rick.ns.cloudflare.com']],
        ])->nameserverReport('example.com');

        $this->assertSame(['gwen.ns.cloudflare.com', 'rick.ns.cloudflare.com'], $report['records']);
        $this->assertSame('Cloudflare', $report['provider']);
    }

    /**
     * @param  array<string, array<string, string|array<int, string>>>  $zone
     */
    private function detector(array $zone): ProviderDetector
    {
        return new ProviderDetector(new ArrayResolver($zone));
    }
}
