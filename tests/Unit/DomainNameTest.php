<?php

namespace SocialDept\TenantDomains\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SocialDept\TenantDomains\Core\DomainName;
use SocialDept\TenantDomains\Tests\TestCase;

class DomainNameTest extends TestCase
{
    #[Test]
    public function it_normalises_hostnames(): void
    {
        $this->assertSame('example.com', DomainName::make('  EXAMPLE.com.  ')->value);
        $this->assertSame('blog.example.com', DomainName::make('https://blog.example.com/path?a=b')->value);
        $this->assertSame('example.com', DomainName::make('HTTP://Example.COM')->value);
    }

    /**
     * The case a hand-maintained suffix table gets wrong. `example.co.uk` is a
     * registrable apex, not a subdomain of `co.uk`, and the difference decides
     * whether the tenant is told to create a CNAME or an A record.
     */
    #[Test]
    #[DataProvider('apexCases')]
    public function it_detects_apex_domains_via_the_public_suffix_list(string $host, bool $isApex): void
    {
        $this->assertSame($isApex, DomainName::make($host)->isApex(), $host);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function apexCases(): array
    {
        return [
            'plain apex' => ['example.com', true],
            'subdomain' => ['blog.example.com', false],
            'two-level suffix apex' => ['example.co.uk', true],
            'two-level suffix subdomain' => ['blog.example.co.uk', false],
            'deep subdomain' => ['a.b.example.com', false],
            'apex under a suffix no hand-rolled list carries' => ['example.com.ar', true],
            'platform-style suffix' => ['example.github.io', true],
        ];
    }

    /**
     * Record names are zone-relative, because every provider appends the zone
     * itself. Handing back an FQDN makes tenants create
     * `_verify.blog.example.com.example.com`.
     */
    #[Test]
    public function it_builds_zone_relative_record_names(): void
    {
        $apex = DomainName::make('example.com');

        $this->assertSame('@', $apex->recordName());
        $this->assertSame('_acme-challenge', $apex->recordName('_acme-challenge'));
        $this->assertSame('*', $apex->recordName('*'));

        $sub = DomainName::make('blog.example.com');

        $this->assertSame('blog', $sub->recordName());
        $this->assertSame('_acme-challenge.blog', $sub->recordName('_acme-challenge'));
        $this->assertSame('*.blog', $sub->recordName('*'));
    }

    #[Test]
    public function it_builds_fully_qualified_record_hosts_for_lookups(): void
    {
        $sub = DomainName::make('blog.example.com');

        $this->assertSame('blog.example.com', $sub->recordHost());
        $this->assertSame('_acme-challenge.blog.example.com', $sub->recordHost('_acme-challenge'));
    }

    #[Test]
    public function it_knows_when_a_host_sits_under_a_parent(): void
    {
        $host = DomainName::make('acme.platform.test');

        $this->assertTrue($host->isUnder('platform.test'));
        $this->assertTrue(DomainName::make('platform.test')->isUnder('platform.test'));
        $this->assertFalse(DomainName::make('notplatform.test')->isUnder('platform.test'));
        $this->assertFalse(DomainName::make('example.com')->isUnder('platform.test'));
    }

    #[Test]
    public function it_drops_the_leftmost_label_to_find_a_parent(): void
    {
        $this->assertSame('blog.example.com', DomainName::make('alice.blog.example.com')->parent()->value);
        $this->assertSame('example.com', DomainName::make('blog.example.com')->parent()->value);

        // An apex has no parent worth having: `com` is a public suffix, not a
        // domain anyone serves.
        $this->assertNull(DomainName::make('example.com')->parent());
        $this->assertNull(DomainName::make('example.co.uk')->parent());
    }

    #[Test]
    public function it_validates_hostname_shape(): void
    {
        $this->assertTrue(DomainName::make('example.com')->isValid());
        $this->assertTrue(DomainName::make('a-b.example.co.uk')->isValid());

        $this->assertFalse(DomainName::make('nodot')->isValid());
        $this->assertFalse(DomainName::make('')->isValid());
        $this->assertFalse(DomainName::make('-bad.example.com')->isValid());
        $this->assertFalse(DomainName::make(str_repeat('a', 250).'.example.com')->isValid());
    }
}
