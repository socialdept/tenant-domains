<?php

namespace SocialDept\TenantDomains\Actions;

use Illuminate\Database\Eloquent\Model;
use SocialDept\TenantDomains\Contracts\ReachabilityProbe;
use SocialDept\TenantDomains\Data\ReconcileResult;
use SocialDept\TenantDomains\Domains;
use SocialDept\TenantDomains\Enums\DomainStatus;
use SocialDept\TenantDomains\Events\DomainConfirming;
use SocialDept\TenantDomains\Events\DomainFailed;
use SocialDept\TenantDomains\Events\DomainVerified;
use SocialDept\TenantDomains\Events\OwnershipProven;
use SocialDept\TenantDomains\Events\RoutingVerified;
use SocialDept\TenantDomains\Exceptions\DnsUnavailable;

/**
 * Carry one domain as far through setup as its DNS currently allows.
 *
 * The same three checks the interactive flow runs, on a timer instead of a
 * click. A tenant pastes their records, closes the tab, and DNS propagates
 * twenty minutes later. Without this, their domain sits pending until they happen
 * to come back.
 *
 * Idempotent and re-entrant: a domain that has already cleared a step is not
 * re-checked for it, and every event fires on **transition**, never once per
 * pass. Running every five minutes, a listener that mailed on each pass would
 * mail forever.
 */
class ReconcileDomain
{
    public function __construct(
        private readonly Domains $domains,
        private readonly ReachabilityProbe $probe,
    ) {
        //
    }

    public function __invoke(Model $domain, bool $dryRun = false): ReconcileResult
    {
        if ($domain->status === DomainStatus::Verified) {
            return ReconcileResult::skipped('already verified');
        }

        $requirements = $this->domains->requirementsFor($domain);

        if (! $requirements->servesAnything()) {
            // A name held in reserve. No DNS to check, nothing to route.
            return ReconcileResult::skipped('serves nothing');
        }

        try {
            if (($result = $this->proveOwnership($domain, $dryRun)) !== null) {
                return $result;
            }

            if (($result = $this->proveRouting($domain, $dryRun)) !== null) {
                return $result;
            }
        } catch (DnsUnavailable $e) {
            // Never a verdict on the tenant's records. Leave the status alone.
            return ReconcileResult::unavailable($e->getMessage());
        }

        if (($result = $this->route($domain, $dryRun)) !== null) {
            return $result;
        }

        return $this->confirm($domain, $dryRun);
    }

    /**
     * Step 1. The TXT record. Proxy-proof, and proves control without moving
     * any traffic, so a tenant can clear it while their existing site keeps
     * serving.
     */
    private function proveOwnership(Model $domain, bool $dryRun): ?ReconcileResult
    {
        if ($domain->ownership_verified_at !== null) {
            return null;
        }

        if (! $this->domains->verifyOwnership($domain)) {
            return ReconcileResult::waiting('ownership record not found');
        }

        if ($dryRun) {
            return ReconcileResult::advanced('would prove ownership');
        }

        $domain->forceFill(['ownership_verified_at' => now()])->save();

        event(new OwnershipProven($domain));

        return ReconcileResult::advanced('ownership proven');
    }

    /**
     * Step 2. The routing record. Nothing is published until this passes. A
     * domain that does not point at us has no business holding our certificate.
     */
    private function proveRouting(Model $domain, bool $dryRun): ?ReconcileResult
    {
        // Already past this step. A routed domain keeps its place across passes.
        if ($domain->status === DomainStatus::Confirming) {
            return null;
        }

        $routing = $this->domains->verifyRouting($domain);

        if (! $routing->verified) {
            $reason = $routing->hint
                ?? ($routing->resolvesElsewhere()
                    ? "This name currently resolves to {$routing->resolved}, which is not us."
                    : 'Routing record not found.');

            $this->recordFailure($domain, $reason, $dryRun);

            return ReconcileResult::waiting($reason);
        }

        if (! $dryRun) {
            event(new RoutingVerified($domain));
        }

        return null;
    }

    /**
     * Step 3. Hand the domain to the edge and move it to Confirming.
     *
     * Confirming rather than Verified because DNS pointing at us is not proof a
     * request arrives. The domain has to be *served* before that can be tested,
     * which is exactly what this state is for.
     */
    private function route(Model $domain, bool $dryRun): ?ReconcileResult
    {
        if ($domain->status === DomainStatus::Confirming) {
            return null;
        }

        if ($dryRun) {
            return ReconcileResult::advanced('would route and begin confirming');
        }

        $domain->forceFill([
            'status' => DomainStatus::Confirming,
            'last_failure_reason' => null,
        ])->save();

        $this->domains->publish($domain);

        event(new DomainConfirming($domain));

        // Stop here. The certificate was only just requested, so probing over
        // HTTPS now would fail for a reason that is not the tenant's.
        return ReconcileResult::advanced('routed, awaiting certificate');
    }

    /**
     * Step 4. A real request, to prove it lands on us.
     *
     * The failure this catches: a redirect rule left over from the tenant's
     * previous host, which passes every DNS check and still breaks the domain.
     */
    private function confirm(Model $domain, bool $dryRun): ReconcileResult
    {
        if (! config('tenant-domains.reachability.enabled', true)) {
            return $this->markVerified($domain, 'dns', $dryRun);
        }

        $result = $this->probe->probe($this->domains->probeUrlsFor($domain));

        if (! $result->reachable) {
            $reason = $result->reason ?? 'The domain did not answer.';

            // Left Confirming, not reverted. Only the interception has to go.
            $this->recordFailure($domain, $reason, $dryRun);

            return ReconcileResult::waiting($reason);
        }

        return $this->markVerified($domain, 'request', $dryRun);
    }

    private function markVerified(Model $domain, string $method, bool $dryRun): ReconcileResult
    {
        if ($dryRun) {
            return ReconcileResult::verified($method);
        }

        $domain->forceFill([
            'status' => DomainStatus::Verified,
            'verified_at' => now(),
            'last_checked_at' => now(),
            'last_failure_reason' => null,
        ])->save();

        event(new DomainVerified($domain, $method));

        return ReconcileResult::verified($method);
    }

    /**
     * Record why a check did not pass, and tell the app **only when the reason
     * changes**.
     *
     * A domain that stays misconfigured is checked every few minutes. Firing on
     * each pass would turn one broken record into a notification every five
     * minutes for as long as it stays broken.
     */
    private function recordFailure(Model $domain, string $reason, bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }

        $changed = $domain->last_failure_reason !== $reason;

        $domain->forceFill([
            'last_checked_at' => now(),
            'last_failure_reason' => $reason,
        ])->save();

        if ($changed) {
            event(new DomainFailed($domain, $reason));
        }
    }
}
