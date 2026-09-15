<?php

namespace SocialDept\TenantDomains\Enums;

/**
 * Things an ingress driver may or may not be able to do, depending on the
 * driver and on how it is configured.
 */
enum IngressCapability: string
{
    /** Certificates covering `*.domain`, for per-account hostnames. */
    case Wildcard = 'wildcard';

    /** A different upstream per hostname, rather than one shared origin. */
    case PerHostnameOrigin = 'per_hostname_origin';

    /** Config changes applied at runtime, without an edge reload. */
    case RuntimeReconfiguration = 'runtime_reconfiguration';

    /** Reports real certificate details rather than just presence. */
    case CertificateInspection = 'certificate_inspection';

    public function describe(): string
    {
        return match ($this) {
            self::Wildcard => 'wildcard certificates',
            self::PerHostnameOrigin => 'a different origin per hostname',
            self::RuntimeReconfiguration => 'reconfiguration without a reload',
            self::CertificateInspection => 'certificate inspection',
        };
    }
}
