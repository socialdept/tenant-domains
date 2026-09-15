<?php

namespace SocialDept\TenantDomains\Contracts;

use SocialDept\TenantDomains\Data\CertificateState;
use SocialDept\TenantDomains\Data\HostnameBinding;
use SocialDept\TenantDomains\Enums\IngressCapability;

/**
 * The edge that terminates TLS and routes requests.
 *
 * Implementations must be idempotent: publishing a binding that is already
 * published changes nothing and sends no write. Reconciliation runs on a
 * schedule, and an edge that churns its config every few minutes will drop
 * connections and re-issue certificates it already has.
 */
interface IngressDriver
{
    /**
     * Serve these hostnames and obtain a certificate for them.
     */
    public function publish(HostnameBinding $binding): void;

    /**
     * Stop serving them and remove whatever config was created for them.
     *
     * Not optional housekeeping: a leftover automation policy keeps the edge
     * retrying ACME against a delegation record the tenant has every reason to
     * have deleted.
     */
    public function withdraw(HostnameBinding $binding): void;

    public function certificateState(string $host): CertificateState;

    /**
     * Whether this driver, as configured, can do something.
     *
     * Asked at boot rather than discovered at runtime. The case this exists for:
     * on-demand certificates cannot be wildcards, and an app that needs one
     * should fail immediately instead of silently never getting a certificate.
     */
    public function supports(IngressCapability $capability): bool;
}
