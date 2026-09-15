<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds this package's columns to an existing `domains` table.
 *
 * The adoption path for an app already on stancl/tenancy. Every column is added
 * only if missing, so this is safe to run against a table that already carries
 * some of them from a hand-rolled implementation.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = $this->table();

        Schema::table($table, function (Blueprint $blueprint) use ($table) {
            $add = fn (string $column, callable $definition) => Schema::hasColumn($table, $column)
                ? null
                : $definition($blueprint);

            $add('platform_base', fn (Blueprint $t) => $t->string('platform_base')->nullable());
            $add('status', fn (Blueprint $t) => $t->string('status')->default('pending'));
            $add('routing_mode', fn (Blueprint $t) => $t->string('routing_mode')->default('cname'));
            $add('acme_delegation_id', fn (Blueprint $t) => $t->string('acme_delegation_id', 32)->nullable());
            $add('ownership_verified_at', fn (Blueprint $t) => $t->timestamp('ownership_verified_at')->nullable());
            $add('verified_at', fn (Blueprint $t) => $t->timestamp('verified_at')->nullable());
            $add('last_checked_at', fn (Blueprint $t) => $t->timestamp('last_checked_at')->nullable());
            $add('last_failure_reason', fn (Blueprint $t) => $t->string('last_failure_reason')->nullable());
            $add('metadata', fn (Blueprint $t) => $t->json('metadata')->nullable());
        });

        // Backfill the platform base for existing rows, so nothing has to fall
        // back to the dot heuristic even once. Rows whose `domain` has no dot
        // are platform subdomains under today's single base.
        if (($base = config('tenant-domains.platform_domain')) !== null) {
            DB::table($table)
                ->whereNull('platform_base')
                ->where('domain', 'not like', '%.%')
                ->update(['platform_base' => $base]);
        }
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->dropColumn([
                'platform_base',
                'status',
                'routing_mode',
                'acme_delegation_id',
                'ownership_verified_at',
                'verified_at',
                'last_checked_at',
                'last_failure_reason',
                'metadata',
            ]);
        });
    }

    private function table(): string
    {
        return config('tenant-domains.table', 'domains');
    }
};
