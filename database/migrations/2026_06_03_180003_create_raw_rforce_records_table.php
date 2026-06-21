<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raw_rforce_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('external_source_id')
                ->constrained('external_sources')
                ->cascadeOnDelete();

            // Most-recent sync run that touched this row. Nullable so an old
            // raw record survives if a run is deleted.
            $table->foreignId('last_sync_run_id')
                ->nullable()
                ->constrained('sync_runs')
                ->nullOnDelete();

            // Salesforce object type, copied from record.attributes.type when
            // present. Nullable — some queries (aggregates, sub-selects) don't
            // return attributes blocks.
            $table->string('source_object_type')->nullable();

            // record.attributes.url — Salesforce's own resource path for the
            // row, e.g. /services/data/v60.0/sobjects/Account/001.../
            $table->string('source_url', 1024)->nullable();

            // record.Id (top-level). Required for change detection.
            $table->string('external_id');

            // Raw Salesforce-shaped JSON record.
            $table->json('payload');

            // SHA-256 of payload, used as the change-detection key.
            $table->string('payload_hash', 64)->nullable();

            $table->timestamps();

            // Idempotent upsert key. Salesforce Ids are globally unique within
            // a source so (source, external_id) is enough — the object-type
            // prefix is encoded in the first 3 chars of the Id anyway.
            $table->unique(
                ['external_source_id', 'external_id'],
                'raw_records_source_external_unique',
            );
            $table->index('source_object_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raw_rforce_records');
    }
};
