<?php

namespace SocialDept\TenantDomains\Data;

/**
 * Everything the edge needs to serve one domain: which names, how to get a
 * certificate for them, and where to send the traffic.
 *
 * The unit an {@see \SocialDept\TenantDomains\Contracts\IngressDriver} publishes
 * and withdraws. Deliberately not a model, because a driver should be testable
 * without a database, and a host app with an unusual setup should be able to
 * bind one by hand.
 */
final class HostnameBinding
{
    /**
     * @param  array<int, string>  $hostnames  The certificate subjects, e.g. ['blog.example.com', '*.blog.example.com'].
     * @param  string|null  $acmeDelegationTarget  Where DNS-01 challenges are written. Null in on-demand mode.
     * @param  string|null  $origin  Upstream to proxy to, e.g. 'container-abc:3000'. Null means the app itself.
     * @param  string  $key  Stable identifier for this binding, used to find and replace its config.
     */
    public function __construct(
        public readonly array $hostnames,
        public readonly ?string $acmeDelegationTarget = null,
        public readonly ?string $origin = null,
        public readonly string $key = '',
    ) {
        //
    }

    public function isWildcard(): bool
    {
        foreach ($this->hostnames as $hostname) {
            if (str_starts_with($hostname, '*.')) {
                return true;
            }
        }

        return false;
    }

    public function isDelegated(): bool
    {
        return $this->acmeDelegationTarget !== null && $this->acmeDelegationTarget !== '';
    }

    /**
     * The name this binding is identified by when reconciling edge config.
     *
     * Falls back to the first hostname, which is stable for as long as the
     * domain exists. Prefer an explicit key when two bindings can transiently share
     * a hostname.
     */
    public function identifier(): string
    {
        return $this->key !== '' ? $this->key : ($this->hostnames[0] ?? '');
    }
}
