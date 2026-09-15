<?php

namespace SocialDept\TenantDomains\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use SocialDept\TenantDomains\Actions\ReconcileDomain;
use SocialDept\TenantDomains\Enums\DomainStatus;
use SocialDept\TenantDomains\Enums\ReconcileOutcome;
use Throwable;

/**
 * Carry every unfinished domain as far through setup as its DNS allows.
 *
 * Schedule it every five minutes:
 *
 *     Schedule::command('domains:reconcile')->everyFiveMinutes();
 *
 * Stateless and idempotent, so it is safe to run by hand, twice at once, or after
 * an interrupted run.
 */
class ReconcileDomainsCommand extends Command
{
    protected $signature = 'domains:reconcile
        {--domain= : Reconcile one domain by name, ignoring the throttle}
        {--dry-run : Report what would change without writing anything}
        {--limit= : Maximum domains to check in this pass}';

    protected $description = 'Re-check unfinished custom domains and advance the ones whose DNS is now right';

    public function handle(ReconcileDomain $reconcile): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $domains = $this->candidates();

        if ($domains->isEmpty()) {
            $this->line('Nothing to reconcile.');

            return self::SUCCESS;
        }

        $tally = array_fill_keys(array_column(ReconcileOutcome::cases(), 'value'), 0);

        foreach ($domains as $domain) {
            try {
                $result = $reconcile($domain, $dryRun);
            } catch (Throwable $e) {
                // One domain's failure must not stop the sweep, or hide itself.
                $this->error("{$domain->fqdn}: {$e->getMessage()}");

                report($e);

                continue;
            }

            $tally[$result->outcome->value]++;

            $this->report($domain, $result->outcome, $result->detail);
        }

        $this->newLine();
        $this->line(($dryRun ? '[dry run] ' : '').sprintf(
            'Checked %d: %d verified, %d advanced, %d waiting, %d skipped, %d unavailable.',
            $domains->count(),
            $tally[ReconcileOutcome::Verified->value],
            $tally[ReconcileOutcome::Advanced->value],
            $tally[ReconcileOutcome::Waiting->value],
            $tally[ReconcileOutcome::Skipped->value],
            $tally[ReconcileOutcome::Unavailable->value],
        ));

        // Nothing checked at all is our outage, not every tenant failing at once.
        if ($tally[ReconcileOutcome::Unavailable->value] === $domains->count()) {
            $this->error('Every lookup failed. DNS resolution is unavailable here.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Everything unfinished, oldest check first.
     *
     * "Unfinished" is anything not Verified, including Failed: a tenant who
     * fixes their records should be picked up without having to find the retry
     * button.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Model>
     */
    private function candidates()
    {
        $model = $this->model();

        $query = $model::query()
            ->whereNull('platform_base')
            ->where('status', '!=', DomainStatus::Verified->value);

        if ($name = $this->option('domain')) {
            // Someone is watching, so "checked 40 seconds ago" is no answer.
            return $query->where('domain', $name)->get();
        }

        $this->applyThrottle($query);

        return $query
            ->orderByRaw('last_checked_at is not null, last_checked_at asc')
            ->limit((int) ($this->option('limit') ?: config('tenant-domains.reconcile.batch', 100)))
            ->get();
    }

    /**
     * Skip domains checked very recently.
     *
     * Overlapping runs, a manual invocation next to a scheduled one, or a queue
     * that fired twice should not mean two DNS lookups and two edge writes for
     * the same domain seconds apart.
     *
     * @param  Builder<Model>  $query
     */
    private function applyThrottle(Builder $query): void
    {
        $seconds = (int) config('tenant-domains.reconcile.throttle', 60);

        if ($seconds <= 0) {
            return;
        }

        $query->where(function (Builder $query) use ($seconds): void {
            $query->whereNull('last_checked_at')
                ->orWhere('last_checked_at', '<=', now()->subSeconds($seconds));
        });
    }

    private function report(Model $domain, ReconcileOutcome $outcome, ?string $detail): void
    {
        $line = "{$domain->fqdn}".($detail !== null ? ": {$detail}" : '');

        match ($outcome) {
            ReconcileOutcome::Verified => $this->info("  ✓ {$line}"),
            ReconcileOutcome::Advanced => $this->line("  → {$line}"),
            ReconcileOutcome::Waiting => $this->line("  · <fg=gray>{$line}</>"),
            ReconcileOutcome::Unavailable => $this->warn("  ? {$line}"),
            ReconcileOutcome::Skipped => $this->line("  - <fg=gray>{$line}</>"),
        };
    }

    /**
     * @return class-string<Model>
     */
    private function model(): string
    {
        return config('tenant-domains.model', \SocialDept\TenantDomains\Models\Domain::class);
    }
}
