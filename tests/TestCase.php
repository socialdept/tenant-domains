<?php

namespace SocialDept\TenantDomains\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use SocialDept\TenantDomains\Core\Platform;
use SocialDept\TenantDomains\Core\PublicSuffixList;
use SocialDept\TenantDomains\TenantDomainsServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // The list is memoised in a static for the life of the process. A test
        // that swaps the path would otherwise leak into every test after it.
        PublicSuffixList::flush();
    }

    protected function getPackageProviders($app): array
    {
        return [TenantDomainsServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('tenant-domains.platform_domain', 'platform.test');
        $app['config']->set('tenant-domains.ownership.prefix', '_verify');
        $app['config']->set('tenant-domains.ownership.value_prefix', 'verification=');
        $app['config']->set('tenant-domains.routing.ingress_ips', ['203.0.113.10']);
    }

    /**
     * The platform under test, built from the same config the app would use.
     */
    protected function platform(): Platform
    {
        return Platform::fromConfig(config('tenant-domains'));
    }
}
