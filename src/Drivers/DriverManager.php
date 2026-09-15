<?php

namespace SocialDept\TenantDomains\Drivers;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use SocialDept\TenantDomains\Contracts\IngressDriver;
use SocialDept\TenantDomains\Contracts\ZoneDriver;
use SocialDept\TenantDomains\Core\Platform;
use SocialDept\TenantDomains\Drivers\Ingress\CaddyIngressDriver;
use SocialDept\TenantDomains\Drivers\Ingress\NullIngressDriver;
use SocialDept\TenantDomains\Drivers\Zone\CloudflareZoneDriver;
use SocialDept\TenantDomains\Drivers\Zone\ManualZoneDriver;
use SocialDept\TenantDomains\Drivers\Zone\NullZoneDriver;

/**
 * Resolves the configured zone and ingress drivers.
 *
 * Custom drivers register through `extendZone()` / `extendIngress()` from a host
 * app's service provider, so adding Route53 or Traefik needs no change here.
 */
class DriverManager
{
    /** @var array<string, callable(Container): ZoneDriver> */
    private array $zoneDrivers = [];

    /** @var array<string, callable(Container): IngressDriver> */
    private array $ingressDrivers = [];

    private ?ZoneDriver $zone = null;

    private ?IngressDriver $ingress = null;

    public function __construct(private readonly Container $app)
    {
        $this->registerDefaults();
    }

    public function zone(): ZoneDriver
    {
        return $this->zone ??= $this->resolve(
            $this->zoneDrivers,
            (string) config('tenant-domains.drivers.zone', 'null'),
            'zone',
        );
    }

    public function ingress(): IngressDriver
    {
        return $this->ingress ??= $this->resolve(
            $this->ingressDrivers,
            (string) config('tenant-domains.drivers.ingress', 'null'),
            'ingress',
        );
    }

    /**
     * @param  callable(Container): ZoneDriver  $factory
     */
    public function extendZone(string $name, callable $factory): self
    {
        $this->zoneDrivers[$name] = $factory;
        $this->zone = null;

        return $this;
    }

    /**
     * @param  callable(Container): IngressDriver  $factory
     */
    public function extendIngress(string $name, callable $factory): self
    {
        $this->ingressDrivers[$name] = $factory;
        $this->ingress = null;

        return $this;
    }

    private function registerDefaults(): void
    {
        $this->zoneDrivers = [
            'null' => fn (): ZoneDriver => new NullZoneDriver(),
            'manual' => fn (): ZoneDriver => new ManualZoneDriver(),
            'cloudflare' => fn (Container $app): ZoneDriver => new CloudflareZoneDriver(
                (string) config('tenant-domains.drivers.zones.cloudflare.api_token'),
                (string) config('tenant-domains.drivers.zones.cloudflare.zone_id'),
            ),
        ];

        $this->ingressDrivers = [
            'null' => fn (Container $app): IngressDriver => new NullIngressDriver(
                $app->make(Platform::class)->certificateMode,
            ),
            'caddy' => fn (Container $app): IngressDriver => new CaddyIngressDriver(
                $app->make(Platform::class),
                (array) config('tenant-domains.drivers.ingresses.caddy', []),
            ),
        ];
    }

    /**
     * @template T of object
     *
     * @param  array<string, callable(Container): T>  $registry
     * @return T
     */
    private function resolve(array $registry, string $name, string $kind): object
    {
        if (! isset($registry[$name])) {
            throw new InvalidArgumentException(sprintf(
                'Unknown tenant-domains %s driver [%s]. Available: %s.',
                $kind,
                $name,
                implode(', ', array_keys($registry)),
            ));
        }

        return $registry[$name]($this->app);
    }
}
