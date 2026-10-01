<?php

namespace SocialDept\TenantDomains\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SocialDept\TenantDomains\Data\DomainInspection;
use SocialDept\TenantDomains\Domains;
use SocialDept\TenantDomains\Enums\WwwRedirect;
use SocialDept\TenantDomains\Tests\TestCase;

/**
 * One call has to answer everything a form needs before a row exists, or the
 * client derives it again and drifts.
 *
 * The drift is not hypothetical: a source app shipped a twenty-entry suffix table
 * to its JavaScript so the add-domain form could decide whether to ask about
 * `www.`, and it disagreed with the server for every registrable suffix outside
 * that list. A writer on a `.com.ng` was never asked, and the answer they never
 * gave was the one the server would have honoured.
 */
class DomainInspectionTest extends TestCase
{
    /* Apex and subdomain
     * - - - - - - - - - - - - - */

    #[Test]
    public function it_reads_an_apex_as_an_apex(): void
    {
        $result = $this->inspect('example.com');

        $this->assertTrue($result->isValid);
        $this->assertTrue($result->isApex);
        $this->assertSame('example.com', $result->apex);
        $this->assertSame('example.com', $result->storeAs);
        $this->assertNull($result->recordPrefix);
        $this->assertSame('example.com', $result->registrableDomain);
    }

    #[Test]
    public function it_reads_a_subdomain_as_a_subdomain(): void
    {
        $result = $this->inspect('blog.example.com');

        $this->assertTrue($result->isValid);
        $this->assertFalse($result->isApex);
        $this->assertSame('blog', $result->recordPrefix);
        $this->assertSame('blog.example.com', $result->storeAs);
        $this->assertFalse($result->wwwIsChoosable);
        $this->assertNull($result->wwwHost);
    }

    /**
     * The whole reason this exists. A hand-kept suffix table gets these wrong,
     * and the verdict decides whether a tenant is offered the www question and
     * whether they are told to create a CNAME or an A record.
     */
    #[Test]
    public function it_knows_multi_label_public_suffixes(): void
    {
        $this->assertTrue($this->inspect('example.co.uk')->isApex);
        $this->assertTrue($this->inspect('example.com.au')->isApex);
        // Outside the twenty entries the old hand-kept table carried.
        $this->assertTrue($this->inspect('example.com.ng')->isApex);
        $this->assertTrue($this->inspect('example.co.ke')->isApex);

        $this->assertFalse($this->inspect('blog.example.co.uk')->isApex);
        $this->assertSame('blog', $this->inspect('blog.example.co.uk')->recordPrefix);
    }

    /* The www question
     * - - - - - - - - - - - - - */

    #[Test]
    public function an_apex_can_be_asked_about_www_and_defaults_to_redirecting_from_it(): void
    {
        $result = $this->inspect('example.com');

        $this->assertTrue($result->wwwIsChoosable);
        $this->assertSame('www.example.com', $result->wwwHost);
        $this->assertSame(WwwRedirect::FromWww, $result->suggestedWwwRedirect);
    }

    /**
     * Typing the `www.` host is itself the answer: that is the address they want,
     * and the row is still the apex, because the two cannot be separate rows.
     */
    #[Test]
    public function a_typed_www_host_is_stored_as_its_apex_and_points_at_www(): void
    {
        $result = $this->inspect('www.example.com');

        $this->assertTrue($result->isWww);
        $this->assertSame('example.com', $result->apex);
        $this->assertSame('example.com', $result->storeAs);
        $this->assertSame('www.example.com', $result->wwwHost);
        $this->assertTrue($result->wwwIsChoosable);
        $this->assertSame(WwwRedirect::ToWww, $result->suggestedWwwRedirect);
    }

    /**
     * `www.blog.example.com` is nobody's alias. Stripping it would store a row the
     * tenant never typed and serve a host they never asked for.
     */
    #[Test]
    public function a_www_on_a_subdomain_is_kept_as_typed(): void
    {
        $result = $this->inspect('www.blog.example.com');

        $this->assertTrue($result->isWww);
        $this->assertSame('www.blog.example.com', $result->storeAs);
        $this->assertFalse($result->wwwIsChoosable);
        $this->assertNull($result->wwwHost);
        $this->assertSame(WwwRedirect::None, $result->suggestedWwwRedirect);
    }

    /* Validity
     * - - - - - - - - - - - - - */

    #[Test]
    public function a_bare_label_is_not_a_custom_domain(): void
    {
        $result = $this->inspect('example');

        $this->assertFalse($result->isValid);
        $this->assertStringContainsString('full domain name', (string) $result->reason);
    }

    #[Test]
    public function nonsense_is_refused(): void
    {
        $this->assertFalse($this->inspect('not a domain')->isValid);
        $this->assertFalse($this->inspect('')->isValid);
        $this->assertFalse($this->inspect('-leading.example.com')->isValid);
    }

    /**
     * The platform's own names are ours to hand out. A tenant registering one as a
     * "custom" domain would be claiming another tenant's address.
     */
    #[Test]
    public function the_platform_domain_and_anything_under_it_is_refused(): void
    {
        foreach (['platform.test', 'someone.platform.test', 'www.platform.test'] as $host) {
            $result = $this->inspect($host);

            $this->assertFalse($result->isValid, "{$host} should be refused");
            $this->assertTrue($result->isPlatformHost);
            $this->assertFalse($result->wwwIsChoosable);
        }
    }

    /* Shape
     * - - - - - - - - - - - - - */

    #[Test]
    public function it_normalises_what_was_typed_while_keeping_the_input(): void
    {
        $result = $this->inspect('  HTTPS://Example.COM/blog  ');

        $this->assertSame('  HTTPS://Example.COM/blog  ', $result->input);
        $this->assertSame('example.com', $result->host);
        $this->assertTrue($result->isValid);
    }

    #[Test]
    public function it_serialises_for_a_client(): void
    {
        $array = $this->inspect('www.example.com')->toArray();

        $this->assertSame('example.com', $array['storeAs']);
        $this->assertTrue($array['wwwIsChoosable']);
        $this->assertSame('to_www', $array['suggestedWwwRedirect']);
        $this->assertSame(json_decode(json_encode($this->inspect('www.example.com')), true), $array);
    }

    private function inspect(string $host): DomainInspection
    {
        return app(Domains::class)->inspect($host);
    }
}
