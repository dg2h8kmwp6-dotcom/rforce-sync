<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('external_source_id')
                ->constrained('external_sources')
                ->cascadeOnDelete();

            // Which logical object this run targeted (Account, Contact, ...).
            // Nullable so we can also model an "all objects" sync-all run.
            $table->string('object_type')->nullable();

            // pending | running | succeeded | partial | failed
            // Mirrors App\Enums\SyncStatus.
            $table->string('status')->default('pending');

            // Cursor used for incremental sync (e.g. last LastModifiedDate).
            $table->timestamp('since')->nullable();
            $table->unsignedInteger('limit')->nullable();

            // Run accounting.
            $table->unsignedInteger('records_fetched')->default(0);
            $table->unsignedInteger('records_upserted')->default(0);
            $table->unsignedInteger('records_failed')->default(0);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            // Last error summary, if any. Per-record errors live in sync_errors.
            $table->text('error_message')->nullable();

            // Arbitrary run metadata (job id, queue name, triggering user, etc.).
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['object_type', 'status']);
            $table->index(['external_source_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};
