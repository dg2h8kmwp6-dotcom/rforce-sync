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

            $table->string('object_type');           // Account, Contact, ...
            $table->string('external_id');           // Salesforce Id
            $table->json('payload');                 // Raw gateway response for this record
            $table->string('payload_hash', 64)->nullable(); // SHA-256 of payload for change detection

            // Cursor field copied off the payload so we can window incremental syncs
            // without re-parsing JSON. Typically LastModifiedDate.
            $table->timestamp('external_modified_at')->nullable();

            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            // Idempotent upsert key.
            $table->unique(['external_source_id', 'object_type', 'external_id'], 'raw_records_source_object_external_unique');
            $table->index(['object_type', 'external_modified_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raw_rforce_records');
    }
};
