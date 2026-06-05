<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bring sync_runs in line with a query-driven world:
 *   - link to the QueryDefinition that triggered the run
 *   - track received / inserted / changed / skipped / failed separately
 *   - precompute duration so dashboards don't have to subtract timestamps
 *   - store the SOQL that was actually executed (so a later edit to the
 *     definition doesn't make historical runs unintelligible)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_runs', function (Blueprint $table): void {
            $table->foreignId('query_definition_id')
                ->nullable()
                ->after('external_source_id')
                ->constrained('query_definitions')
                ->nullOnDelete();

            // The SOQL that ran. Captured at start so edits don't rewrite history.
            $table->text('soql')->nullable()->after('object_type');

            $table->unsignedInteger('records_received')->default(0)->after('records_fetched');
            $table->unsignedInteger('records_inserted')->default(0)->after('records_received');
            $table->unsignedInteger('records_changed')->default(0)->after('records_inserted');
            $table->unsignedInteger('records_skipped')->default(0)->after('records_changed');

            $table->unsignedBigInteger('duration_ms')->nullable()->after('finished_at');

            $table->index(['query_definition_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::table('sync_runs', function (Blueprint $table): void {
            $table->dropForeign(['query_definition_id']);
            $table->dropIndex(['query_definition_id', 'started_at']);
            $table->dropColumn([
                'query_definition_id',
                'soql',
                'records_received',
                'records_inserted',
                'records_changed',
                'records_skipped',
                'duration_ms',
            ]);
        });
    }
};
