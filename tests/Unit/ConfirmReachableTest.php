<?php

namespace SocialDept\TenantDomains\Tests\Unit;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use SocialDept\TenantDomains\Actions\ConfirmReachable;
use SocialDept\TenantDomains\Tests\TestCase;

class ConfirmReachableTest extends TestCase
{
    #[Test]
    public function a_plain_success_is_reachable(): void
    {
        Http::fake(['https://example.com/' => Http::response('hello', 200)]);

        $result = (new ConfirmReachable())->probe(['https://example.com/']);

        $this->assertTrue($result->reachable);
        $this->assertSame(200, $result->status);
    }

    /**
     * The failure this whole action exists for: a tenant keeps a redirect rule
     * from a previous host, passes every DNS check, and the domain never works.
     *
     * Following the redirect would hide it, because the destination usually answers
     * 200.
     */
    #[Test]
    public function a_redirect_is_a_failure_and_names_where_it_goes(): void
    {
        Http::fake([
            'https://example.com/' => Http::response('', 302, ['Location' => 'https://their-old-site.example']),
        ]);

        $result = (new ConfirmReachable())->probe(['https://example.com/']);

        $this->assertFalse($result->reachable);
        $this->assertSame(302, $result->status);
        $this->assertStringContainsString('their-old-site.example', $result->reason);
        $this->assertStringContainsString('redirect or page rule', $result->reason);
    }

    #[Test]
    public function an_error_response_is_a_failure(): void
    {
        Http::fake(['https://example.com/' => Http::response('nope', 403)]);

        $result = (new ConfirmReachable())->probe(['https://example.com/']);

        $this->assertFalse($result->reachable);
        $this->assertSame(403, $result->status);
    }

    #[Test]
    public function a_connection_failure_is_reported_against_the_host(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection refused'));

        $result = (new ConfirmReachable())->probe(['https://example.com/']);

        $this->assertFalse($result->reachable);
        $this->assertStringContainsString('example.com', $result->reason);
    }

    /**
     * A 200 from something that is not us still fails, when the host app can
     * recognise itself. A cached page from the tenant's old site answers 200.
     */
    #[Test]
    public function a_success_from_the_wrong_server_fails_the_expectation(): void
    {
        Http::fake(['https://example.com/' => Http::response('somebody else', 200)]);

        $probe = new ConfirmReachable(
            expectation: fn ($response): bool => str_contains($response->body(), 'our-marker'),
        );

        $result = $probe->probe(['https://example.com/']);

        $this->assertFalse($result->reachable);
        $this->assertStringContainsString('other than us', $result->reason);
    }

    /**
     * A redirect back to the same host cannot have happened unless the request
     * reached us, which is the only thing this probe proves. An app that sends
     * `/` to `/dashboard` is not intercepting itself.
     */
    #[Test]
    public function a_same_host_redirect_counts_as_reachable(): void
    {
        Http::fake([
            'https://example.com/' => Http::response('', 302, ['Location' => 'https://example.com/dashboard']),
        ]);

        $this->assertTrue((new ConfirmReachable())->probe(['https://example.com/'])->reachable);
    }

    /**
     * The case that made the probe unusable in a real app. A tenant platform
     * that 301s every non-primary hostname to the publication's primary would
     * read its own redirect as an interception, so no domain could ever finish.
     */
    #[Test]
    public function a_redirect_to_a_host_the_app_claims_counts_as_reachable(): void
    {
        Http::fake([
            'https://custom.example/' => Http::response('', 301, ['Location' => 'https://mypub.platform.test/']),
        ]);

        $probe = new ConfirmReachable(
            acceptRedirectTo: fn (string $target): bool => str_ends_with($target, '.platform.test'),
        );

        $this->assertTrue($probe->probe(['https://custom.example/'])->reachable);
    }

    /**
     * Claiming your own domains must not claim everyone else's.
     */
    #[Test]
    public function a_redirect_the_app_does_not_claim_still_fails(): void
    {
        Http::fake([
            'https://custom.example/' => Http::response('', 301, ['Location' => 'https://their-old-host.example/']),
        ]);

        $probe = new ConfirmReachable(
            acceptRedirectTo: fn (string $target): bool => str_ends_with($target, '.platform.test'),
        );

        $result = $probe->probe(['https://custom.example/']);

        $this->assertFalse($result->reachable);
        $this->assertStringContainsString('their-old-host.example', $result->reason);
    }

    /**
     * A domain doing two jobs is asked both ways: they are separate routes at
     * the edge, and a rule can intercept one while leaving the other alone.
     */
    #[Test]
    public function every_url_must_answer_and_the_first_failure_is_returned(): void
    {
        Http::fake([
            'https://example.com/' => Http::response('ok', 200),
            'https://alice.example.com/' => Http::response('', 302, ['Location' => 'https://elsewhere.example']),
        ]);

        $result = (new ConfirmReachable())->probe([
            'https://example.com/',
            'https://alice.example.com/',
        ]);

        $this->assertFalse($result->reachable);
        $this->assertSame('https://alice.example.com/', $result->url);
    }
}
