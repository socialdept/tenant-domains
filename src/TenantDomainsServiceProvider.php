<?php

namespace SocialDept\TenantDomains;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use SocialDept\TenantDomains\Actions\ConfirmReachable;
use SocialDept\TenantDomains\Console\ReconcileDomainsCommand;
use SocialDept\TenantDomains\Console\RefreshPublicSuffixListCommand;
use SocialDept\TenantDomains\Contracts\DnsResolver;
use SocialDept\TenantDomains\Contracts\IngressDriver;
use SocialDept\TenantDomains\Contracts\ReachabilityProbe;
use SocialDept\TenantDomains\Contracts\ZoneDriver;
use SocialDept\TenantDomains\Core\Detection\ProviderDetector;
use SocialDept\TenantDomains\Core\Detection\ProxyDetector;
use SocialDept\TenantDomains\Core\Platform;
use SocialDept\TenantDomains\Dns\SystemResolver;
use SocialDept\TenantDomains\Drivers\DriverManager;
use SocialDept\TenantDomains\Http\CertificateAuthorityController;

class TenantDomainsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/tenant-domains.php', 'tenant-domains');

        $this->app->singleton(Platform::class, fn () => Platform::fromConfig(config('tenant-domains')));

        $this->app->singleton(DnsResolver::class, fn () => new SystemResolver(
            (string) config('tenant-domains.dns.canary', '1.1.1.1'),
        ));

        $this->app->singleton(DriverManager::class, fn ($app) => new DriverManager($app));

        $this->app->bind(ZoneDriver::class, fn ($app) => $app->make(DriverManager::class)->zone());
        $this->app->bind(IngressDriver::class, fn ($app) => $app->make(DriverManager::class)->ingress());

        // Rebind this to claim your own redirects. An app that sends unverified
        // hostnames anywhere of its own needs to, or the probe reads that as an
        // interception and no domain ever finishes.
        $this->app->singleton(ReachabilityProbe::class, fn () => new ConfirmReachable(
            (int) config('tenant-domains.reachability.timeout', 8),
        ));

        $this->app->singleton(ProviderDetector::class);
        $this->app->singleton(ProxyDetector::class);
        $this->app->singleton(Domains::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/tenant-domains.php' => config_path('tenant-domains.php'),
            ], 'tenant-domains-config');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations/create_domains_table.php' => database_path('migrations/0000_00_00_000001_create_domains_table.php'),
            ], 'tenant-domains-migrations');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations/add_custom_domain_columns.php' => database_path('migrations/0000_00_00_000001_add_custom_domain_columns.php'),
            ], 'tenant-domains-migrations-columns');

            $this->commands([
                ReconcileDomainsCommand::class,
                RefreshPublicSuffixListCommand::class,
            ]);
        }

        $this->registerAuthorityEndpoint();
    }

    /**
     * Register the endpoint the edge asks before issuing a certificate.
     *
     * Opt out with `authority_endpoint.enabled` and register it yourself if you
     * want it under your own API conventions or behind your own middleware. The
     * path only has to agree with whatever your edge is configured to call.
     */
    protected function registerAuthorityEndpoint(): void
    {
        if (! config('tenant-domains.authority_endpoint.enabled', true)) {
            return;
        }

        $route = Route::get(
            (string) config('tenant-domains.authority_endpoint.path', 'api/caddy/verify'),
            CertificateAuthorityController::class,
        )->middleware((array) config('tenant-domains.authority_endpoint.middleware', []));

        if ($name = config('tenant-domains.authority_endpoint.name')) {
            $route->name($name);
        }
    }
}
