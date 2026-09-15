<?php

namespace SocialDept\TenantDomains\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SocialDept\TenantDomains\Actions\VerifyOwnership;
use SocialDept\TenantDomains\Dns\ArrayResolver;
use SocialDept\TenantDomains\Exceptions\DnsUnavailable;
use SocialDept\TenantDomains\Tests\TestCase;

class VerifyOwnershipTest extends TestCase
{
    #[Test]
    public function it_finds_the_token_at_the_prefixed_host(): void
    {
        $this->assertTrue($this->verify('blog.example.com', 'tok_123', new ArrayResolver([
            '_verify.blog.example.com' => ['TXT' => 'verification=tok_123'],
        ])));
    }

    #[Test]
    public function the_apex_record_sits_directly_under_the_domain(): void
    {
        $this->assertTrue($this->verify('example.com', 'tok_123', new ArrayResolver([
            '_verify.example.com' => ['TXT' => 'verification=tok_123'],
        ])));
    }

    #[Test]
    public function a_different_tenants_token_does_not_verify(): void
    {
        $this->assertFalse($this->verify('example.com', 'tok_123', new ArrayResolver([
            '_verify.example.com' => ['TXT' => 'verification=tok_someone_else'],
        ])));
    }

    #[Test]
    public function a_missing_record_is_simply_false(): void
    {
        $this->assertFalse($this->verify('example.com', 'tok_123', new ArrayResolver()));
    }

    /**
     * Other TXT records live at the same name in real zones. Finding ours among
     * them is the normal case, not the exception.
     */
    #[Test]
    public function it_finds_the_token_among_unrelated_txt_records(): void
    {
        $this->assertTrue($this->verify('example.com', 'tok_123', new ArrayResolver([
            '_verify.example.com' => ['TXT' => [
                'some-other-service=abc',
                'verification=tok_123',
            ]],
        ])));
    }

    /**
     * Providers split long TXT values into 255-byte chunks. `dns_get_record()`
     * joins them into `txt` but keeps the pieces in `entries`, and which one
     * carries the whole value varies.
     */
    #[Test]
    public function it_reassembles_a_chunked_txt_record(): void
    {
        $resolver = new ArrayResolver();
        $resolver->set('_verify.example.com', ['TXT' => 'ignored']);

        $action = new VerifyOwnership(
            new class () extends ArrayResolver {
                public function records(string $host, int $type): array
                {
                    return [[
                        'host' => $host,
                        'type' => 'TXT',
                        'txt' => 'verification=tok_',
                        'entries' => ['verification=tok_', '123'],
                    ]];
                }
            },
            $this->platform(),
        );

        $this->assertTrue($action('example.com', 'tok_123'));
    }

    /**
     * TXT is never proxied, so this is the signal that survives an orange cloud
     * That only holds if a resolver outage is distinguished from a missing record.
     */
    #[Test]
    public function a_resolver_outage_throws_rather_than_reporting_an_unowned_domain(): void
    {
        $resolver = (new ArrayResolver())->fail('_verify.example.com');

        $this->expectException(DnsUnavailable::class);

        $this->verify('example.com', 'tok_123', $resolver);
    }

    private function verify(string $host, string $token, ArrayResolver $resolver): bool
    {
        $action = new VerifyOwnership($resolver, $this->platform());

        return $action($host, $token);
    }
}
