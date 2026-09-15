<?php

namespace SocialDept\TenantDomains\Drivers\Ingress;

use SocialDept\TenantDomains\Contracts\IngressDriver;
use SocialDept\TenantDomains\Data\CertificateState;
use SocialDept\TenantDomains\Data\HostnameBinding;
use SocialDept\TenantDomains\Enums\CertificateMode;
use SocialDept\TenantDomains\Enums\IngressCapability;

/**
 * An edge that needs no programming.
 *
 * The right driver for a statically configured edge: one origin for every custom
 * domain, certificates obtained on demand, and an ask endpoint as the only gate.
 * Publishing is genuinely a no-op there, and saying so is more honest than
 * pretending to reconcile config that nothing reads.
 *
 * Capabilities still answer truthfully, so an app that needs wildcards fails at
 * boot rather than waiting for a certificate that can never be issued.
 */
class NullIngressDriver implements IngressDriver
{
    public function __construct(
        private readonly CertificateMode $mode = CertificateMode::OnDemand,
    ) {
        //
    }

    public function publish(HostnameBinding $binding): void
    {
        //
    }

    public function withdraw(HostnameBinding $binding): void
    {
        //
    }

    public function certificateState(string $host): CertificateState
    {
        return CertificateState::pending();
    }

    public function supports(IngressCapability $capability): bool
    {
        return match ($capability) {
            IngressCapability::Wildcard => $this->mode->supportsWildcards(),
            default => false,
        };
    }
}
