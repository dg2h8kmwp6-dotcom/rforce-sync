<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link sync_runs back to the QueryDefinition that triggered them and capture
 * the exact SOQL that ran (so a later edit to the definition doesn't make
 * historical runs unintelligible). Also precomputes duration so dashboards
 * don't have to subtract timestamps.
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

            $table->text('soql')->nullable()->after('target_dataset');

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
                'duration_ms',
            ]);
        });
    }
};
