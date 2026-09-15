<?php

namespace SocialDept\TenantDomains\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SocialDept\TenantDomains\Core\DomainName;
use SocialDept\TenantDomains\Data\DomainRequirements;
use SocialDept\TenantDomains\Data\Instructions;
use SocialDept\TenantDomains\Enums\CertificateMode;
use SocialDept\TenantDomains\Enums\RoutingMode;
use SocialDept\TenantDomains\Tests\TestCase;

class InstructionsTest extends TestCase
{
    #[Test]
    public function it_builds_the_three_records_for_a_subdomain(): void
    {
        $instructions = $this->build('blog.example.com');

        $this->assertSame(['root', 'ownership', 'acme'], array_keys($instructions->records));

        $root = $instructions->record('root');
        $this->assertSame('CNAME', $root->type);
        $this->assertSame('blog', $root->name);
        $this->assertSame('to.platform.test', $root->value);

        $ownership = $instructions->record('ownership');
        $this->assertSame('TXT', $ownership->type);
        $this->assertSame('_verify.blog', $ownership->name);
        $this->assertSame('_verify.blog.example.com', $ownership->host);
        $this->assertSame('verification=tok_123', $ownership->value);

        $acme = $instructions->record('acme');
        $this->assertSame('_acme-challenge.blog', $acme->name);
        $this->assertSame('abc123.acme.platform.test', $acme->value);
    }

    /**
     * At the apex a bare record is written `@`, which is what every provider's
     * Name field expects.
     */
    #[Test]
    public function it_writes_apex_record_names_as_an_at_sign(): void
    {
        $instructions = $this->build('example.com', routingMode: RoutingMode::ARecord);

        $this->assertSame('@', $instructions->record('root')->name);
        $this->assertSame('A', $instructions->record('root')->type);
        $this->assertSame('203.0.113.10', $instructions->record('root')->value);
        $this->assertSame('_verify', $instructions->record('ownership')->name);
        $this->assertSame('_acme-challenge', $instructions->record('acme')->name);
    }

    /**
     * The routing-mode toggle is only meaningful at the apex. A subdomain always
     * takes a CNAME, and offering the choice invites the worse answer.
     */
    #[Test]
    public function only_apex_domains_may_choose_a_routing_mode(): void
    {
        $this->assertTrue($this->build('example.com')->routingModeIsChoosable());
        $this->assertFalse($this->build('blog.example.com')->routingModeIsChoosable());
    }

    #[Test]
    public function it_adds_a_wildcard_record_only_when_required(): void
    {
        $without = $this->build('example.com');
        $this->assertNull($without->record('wildcard'));

        $with = $this->build('example.com', requirements: new DomainRequirements(root: true, wildcard: true));
        $this->assertSame('*', $with->record('wildcard')->name);
        $this->assertSame('*.example.com', $with->record('wildcard')->host);
    }

    #[Test]
    public function a_handle_only_domain_is_not_asked_for_a_root_record(): void
    {
        $instructions = $this->build(
            'example.com',
            requirements: new DomainRequirements(root: false, wildcard: true),
        );

        $this->assertNull($instructions->record('root'));
        $this->assertNotNull($instructions->record('wildcard'));
    }

    /**
     * On-demand mode never reads a delegation record, so it must never ask for
     * one. One source app asks every customer to create this record and has
     * nothing on the server that looks at it.
     */
    #[Test]
    public function on_demand_mode_does_not_ask_for_an_acme_record(): void
    {
        config()->set('tenant-domains.certificates.mode', CertificateMode::OnDemand->value);

        $instructions = $this->build('blog.example.com');

        $this->assertNull($instructions->record('acme'));
        $this->assertNotNull($instructions->record('ownership'));
    }

    #[Test]
    public function it_serialises_for_a_frontend(): void
    {
        $array = $this->build('blog.example.com')->toArray();

        $this->assertSame('blog.example.com', $array['domain']);
        $this->assertFalse($array['isApex']);
        $this->assertSame('cname', $array['routingMode']);
        $this->assertSame(['root' => true, 'wildcard' => false], $array['requirements']);
        $this->assertArrayHasKey('root', $array['records']);
        $this->assertSame('_verify.blog', $array['records']['ownership']['name']);
    }

    private function build(
        string $host,
        ?DomainRequirements $requirements = null,
        ?RoutingMode $routingMode = null,
    ): Instructions {
        $name = DomainName::make($host);

        return Instructions::build(
            domain: $name,
            platform: $this->platform(),
            requirements: $requirements ?? DomainRequirements::default(),
            routingMode: $routingMode ?? RoutingMode::Cname,
            ownershipToken: 'tok_123',
            delegationId: 'abc123',
        );
    }
}
