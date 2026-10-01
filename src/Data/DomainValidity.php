<?php

namespace SocialDept\TenantDomains\Data;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Whether a typed hostname is usable at all, and why not when it is not.
 *
 * The reason is written for a form to show, so it names what to do rather than
 * which rule failed.
 *
 * @implements Arrayable<string, mixed>
 */
final class DomainValidity implements Arrayable
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?string $error = null,
    ) {
        //
    }

    public static function ok(): self
    {
        return new self(true);
    }

    public static function failed(string $error): self
    {
        return new self(false, $error);
    }

    /**
     * @return array{ok: bool, error: string|null}
     */
    public function toArray(): array
    {
        return ['ok' => $this->ok, 'error' => $this->error];
    }
}
