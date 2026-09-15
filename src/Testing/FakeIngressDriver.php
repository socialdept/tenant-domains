<?php

namespace SocialDept\TenantDomains\Testing;

use PHPUnit\Framework\Assert;
use SocialDept\TenantDomains\Contracts\IngressDriver;
use SocialDept\TenantDomains\Data\CertificateState;
use SocialDept\TenantDomains\Data\HostnameBinding;
use SocialDept\TenantDomains\Enums\IngressCapability;

/**
 * Records what would have been published, and lets a test assert on it.
 *
 * The point of the ingress contract: an app can prove its domain lifecycle is
 * correct without a Caddy to talk to.
 */
class FakeIngressDriver implements IngressDriver
{
    /** @var array<int, HostnameBinding> */
    public array $published = [];

    /** @var array<int, HostnameBinding> */
    public array $withdrawn = [];

    /** @var array<string, CertificateState> */
    public array $certificates = [];

    /** @param array<int, IngressCapability> $capabilities */
    public function __construct(
        private array $capabilities = [
            IngressCapability::Wildcard,
            IngressCapability::PerHostnameOrigin,
            IngressCapability::RuntimeReconfiguration,
            IngressCapability::CertificateInspection,
        ],
    ) {
        //
    }

    public function publish(HostnameBinding $binding): void
    {
        $this->published[] = $binding;
    }

    public function withdraw(HostnameBinding $binding): void
    {
        $this->withdrawn[] = $binding;
    }

    public function certificateState(string $host): CertificateState
    {
        return $this->certificates[$host] ?? CertificateState::pending();
    }

    public function supports(IngressCapability $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }

    public function pretendIssued(string $host, ?string $issuer = "Let's Encrypt"): self
    {
        $this->certificates[$host] = new CertificateState(
            provisioned: true,
            subject: $host,
            issuer: $issuer,
        );

        return $this;
    }

    public function assertPublished(string $hostname, ?string $delegatedTo = null): void
    {
        foreach ($this->published as $binding) {
            if (! in_array($hostname, $binding->hostnames, true)) {
                continue;
            }

            if ($delegatedTo !== null && $binding->acmeDelegationTarget !== $delegatedTo) {
                continue;
            }

            Assert::assertTrue(true);

            return;
        }

        Assert::fail($delegatedTo === null
            ? "Expected [{$hostname}] to have been published to the edge."
            : "Expected [{$hostname}] to have been published with delegation target [{$delegatedTo}].");
    }

    public function assertNotPublished(string $hostname): void
    {
        foreach ($this->published as $binding) {
            if (in_array($hostname, $binding->hostnames, true)) {
                Assert::fail("Expected [{$hostname}] not to have been published to the edge.");
            }
        }

        Assert::assertTrue(true);
    }

    public function assertWithdrawn(string $hostname): void
    {
        foreach ($this->withdrawn as $binding) {
            if (in_array($hostname, $binding->hostnames, true)) {
                Assert::assertTrue(true);

                return;
            }
        }

        Assert::fail("Expected [{$hostname}] to have been withdrawn from the edge.");
    }

    public function assertNothingPublished(): void
    {
        Assert::assertSame([], $this->published, 'Expected nothing to have been published to the edge.');
    }
}
