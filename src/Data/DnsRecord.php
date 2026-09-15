<?php

namespace SocialDept\TenantDomains\Data;

use Illuminate\Contracts\Support\Arrayable;

/**
 * One DNS record a tenant is asked to create, or one we manage in our own zone.
 *
 * Carries **both** names on purpose. `name` is zone-relative, because that is
 * what a provider's Name field wants, since it appends the zone itself. `host` is
 * the fully qualified name, because that is what a lookup needs. One source app
 * displayed the FQDN in the Name field, so tenants pasting it created
 * `_verify.blog.example.com.example.com`.
 *
 * @implements Arrayable<string, mixed>
 */
final class DnsRecord implements Arrayable
{
    public function __construct(
        public readonly string $type,
        public readonly string $name,
        public readonly string $host,
        public readonly string $value,
        public readonly bool $proxied = false,
        public readonly ?string $purpose = null,
    ) {
        //
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'name' => $this->name,
            'host' => $this->host,
            'value' => $this->value,
            'proxied' => $this->proxied,
            'purpose' => $this->purpose,
        ];
    }
}
