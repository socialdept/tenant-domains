<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The full table, for an app that does not already have one.
 *
 * An app already using stancl/tenancy has a `domains` table. Publish
 * `tenant-domains-migrations-columns` instead, which adds only what this package
 * needs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->id();

            // The hostname. For a platform subdomain this is the bare label
            // like 'acme'. For a custom domain it is the full name.
            $table->string('domain')->unique();

            // Non-null means a platform subdomain and `domain` holds the label.
            // Null means a custom domain and `domain` holds the FQDN.
            //
            // Deliberately not inferred from whether `domain` contains a dot.
            // That heuristic works only while there is exactly one platform base
            // domain, and misfiles every subdomain the moment a second is added.
            $table->string('platform_base')->nullable();

            $table->unsignedBigInteger('tenant_id')->index();

            $table->string('status')->default('pending')->index();
            $table->string('routing_mode')->default('cname');
            $table->boolean('is_primary')->default(false);

            // Minted once, never regenerated: the tenant may already have
            // `_acme-challenge` CNAMEd to it, and a new id silently invalidates
            // their DNS so no certificate ever issues.
            $table->string('acme_delegation_id', 32)->nullable()->unique();

            // Ownership proven. Separate from `status`, which is about being in
            // service: a domain can be owned long before it is live.
            $table->timestamp('ownership_verified_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_failure_reason')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return config('tenant-domains.table', 'domains');
    }
};
