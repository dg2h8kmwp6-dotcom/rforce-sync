<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add audit columns so we can answer:
 *   - when did we first see this record?
 *   - when did we most recently see it (regardless of payload change)?
 *   - when did we last (re)derive normalized rows from it?
 *   - which saved query produced it?
 *
 * The pre-existing `fetched_at` column is preserved for backwards compatibility
 * but is functionally equivalent to `last_seen_at`. New code should write to
 * both; reads should prefer `last_seen_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raw_rforce_records', function (Blueprint $table): void {
            $table->foreignId('query_definition_id')
                ->nullable()
                ->after('last_sync_run_id')
                ->constrained('query_definitions')
                ->nullOnDelete();

            $table->timestamp('first_seen_at')->nullable()->after('external_modified_at');
            $table->timestamp('last_seen_at')->nullable()->after('first_seen_at');
            $table->timestamp('last_synced_at')->nullable()->after('last_seen_at');

            $table->index(['query_definition_id', 'last_seen_at']);
            $table->index(['object_type', 'last_seen_at']);
            $table->index('payload_hash');
        });
    }

    public function down(): void
    {
        Schema::table('raw_rforce_records', function (Blueprint $table): void {
            $table->dropForeign(['query_definition_id']);
            $table->dropIndex(['query_definition_id', 'last_seen_at']);
            $table->dropIndex(['object_type', 'last_seen_at']);
            $table->dropIndex(['payload_hash']);
            $table->dropColumn([
                'query_definition_id',
                'first_seen_at',
                'last_seen_at',
                'last_synced_at',
            ]);
        });
    }
};
