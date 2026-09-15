<?php

namespace SocialDept\TenantDomains\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use SocialDept\TenantDomains\Core\DomainName;
use SocialDept\TenantDomains\Core\Platform;
use SocialDept\TenantDomains\Data\DomainRequirements;
use SocialDept\TenantDomains\Data\Instructions;
use SocialDept\TenantDomains\Enums\RoutingMode;
use SocialDept\TenantDomains\Tests\TestCase;

class PlatformTest extends TestCase
{
    #[Test]
    public function the_platform_placeholder_expands_in_every_derived_name(): void
    {
        $platform = $this->platformWith([]);

        $this->assertSame('to.platform.test', $platform->cnameTarget);
        $this->assertSame('acme.platform.test', $platform->delegationSuffix);
        $this->assertSame('abc123.acme.platform.test', $platform->delegationTarget('abc123'));
    }

    /**
     * The `to.` convention is a default, not a rule. An app arriving from another
     * setup should not have to create a record shaped like ours.
     */
    #[Test]
    public function the_cname_target_can_be_any_hostname_under_the_platform(): void
    {
        $platform = $this->platformWith(['routing.cname_target' => 'ingress.{platform}']);

        $this->assertSame('ingress.platform.test', $platform->cnameTarget);
    }

    /**
     * The target need not live under the platform domain at all. A load
     * balancer, a CDN hostname or an existing ingress on another domain are all
     * legitimate, and the placeholder is optional precisely so they work.
     */
    #[Test]
    public function the_cname_target_can_point_at_a_completely_separate_domain(): void
    {
        $platform = $this->platformWith(['routing.cname_target' => 'lb-7a2.eu-west.example.net']);

        $this->assertSame('lb-7a2.eu-west.example.net', $platform->cnameTarget);

        $instructions = Instructions::build(
            domain: DomainName::make('blog.example.com'),
            platform: $platform,
            requirements: DomainRequirements::default(),
            routingMode: RoutingMode::Cname,
            ownershipToken: 'tok_123',
            delegationId: 'abc123',
        );

        $this->assertSame('lb-7a2.eu-west.example.net', $instructions->record('root')->value);
    }

    /**
     * Delegation targets live in whatever zone can actually answer DNS-01, which
     * is not always a subdomain of the platform.
     */
    #[Test]
    public function the_delegation_suffix_is_independent_of_the_platform_domain(): void
    {
        $platform = $this->platformWith(['certificates.delegation_suffix' => 'dcv.acme-zone.example']);

        $this->assertSame('abc123.dcv.acme-zone.example', $platform->delegationTarget('abc123'));
    }

    #[Test]
    public function the_ownership_record_naming_is_fully_configurable(): void
    {
        $platform = $this->platformWith([
            'ownership.prefix' => '_offprint',
            'ownership.value_prefix' => 'aturi=',
        ]);

        $this->assertSame('_offprint', $platform->ownershipPrefix);
        $this->assertSame('aturi=at://did:plc:xyz', $platform->ownershipValue('at://did:plc:xyz'));
    }

    /**
     * Without a platform domain nothing downstream can be named, so failing at
     * construction beats emitting instructions full of empty hostnames.
     */
    #[Test]
    public function a_missing_platform_domain_fails_loudly(): void
    {
        config()->set('tenant-domains.platform_domain', '');

        $this->expectException(InvalidArgumentException::class);

        Platform::fromConfig(config('tenant-domains'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function platformWith(array $overrides): Platform
    {
        foreach ($overrides as $key => $value) {
            config()->set("tenant-domains.{$key}", $value);
        }

        return Platform::fromConfig(config('tenant-domains'));
    }
}
