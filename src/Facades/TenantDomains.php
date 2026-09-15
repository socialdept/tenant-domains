<?php

namespace SocialDept\TenantDomains\Facades;

use Illuminate\Support\Facades\Facade;
use SocialDept\TenantDomains\Domains;

/**
 * @method static \SocialDept\TenantDomains\Core\Platform platform()
 * @method static \SocialDept\TenantDomains\Data\Instructions instructionsFor(\Illuminate\Database\Eloquent\Model $domain)
 * @method static \SocialDept\TenantDomains\Enums\RoutingMode recommendRoutingMode(\SocialDept\TenantDomains\Core\DomainName|string $domain)
 * @method static string|null ensureDelegationId(\Illuminate\Database\Eloquent\Model $domain)
 * @method static bool verifyOwnership(\Illuminate\Database\Eloquent\Model $domain)
 * @method static \SocialDept\TenantDomains\Data\RoutingResult verifyRouting(\Illuminate\Database\Eloquent\Model $domain)
 * @method static void publish(\Illuminate\Database\Eloquent\Model $domain, ?string $origin = null)
 * @method static void withdraw(\Illuminate\Database\Eloquent\Model $domain)
 * @method static \SocialDept\TenantDomains\Data\CertificateState certificateState(\Illuminate\Database\Eloquent\Model $domain)
 * @method static \SocialDept\TenantDomains\Data\HostnameBinding bindingFor(\Illuminate\Database\Eloquent\Model $domain, ?string $origin = null)
 * @method static bool mayIssueCertificateFor(\SocialDept\TenantDomains\Core\DomainName $host)
 * @method static \SocialDept\TenantDomains\Data\DomainRequirements requirementsFor(\Illuminate\Database\Eloquent\Model $domain)
 *
 * @see Domains
 */
class TenantDomains extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Domains::class;
    }
}
