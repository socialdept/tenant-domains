<?php

namespace SocialDept\TenantDomains\Data;

use Illuminate\Contracts\Support\Arrayable;

/**
 * What the edge currently holds for a hostname.
 *
 * `provisioned` false with a null `error` means "not yet", which during setup is
 * the expected answer and must not be rendered as a failure.
 *
 * @implements Arrayable<string, mixed>
 */
final class CertificateState implements Arrayable
{
    public function __construct(
        public readonly bool $provisioned,
        public readonly ?string $subject = null,
        public readonly ?string $issuer = null,
        public readonly ?string $expiresAt = null,
        public readonly ?string $error = null,
    ) {
        //
    }

    public static function pending(): self
    {
        return new self(provisioned: false);
    }

    public static function failed(string $error): self
    {
        return new self(provisioned: false, error: $error);
    }

    public function toArray(): array
    {
        return [
            'provisioned' => $this->provisioned,
            'subject' => $this->subject,
            'issuer' => $this->issuer,
            'expiresAt' => $this->expiresAt,
            'error' => $this->error,
        ];
    }
}
