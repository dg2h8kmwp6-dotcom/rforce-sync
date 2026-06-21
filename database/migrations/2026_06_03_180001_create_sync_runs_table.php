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

            // Business-named dataset this run targeted (accounts/contacts/...).
            // Mirrors QueryDefinition.target_dataset. Nullable for ad-hoc SOQL.
            $table->string('target_dataset')->nullable();

            // pending | running | succeeded | partial | failed | cancelled
            // Mirrors App\Enums\SyncStatus.
            $table->string('status')->default('pending');

            // Cursor used for incremental sync (e.g. last LastModifiedDate).
            $table->timestamp('since')->nullable();
            $table->unsignedInteger('limit')->nullable();

            // Run accounting.
            $table->unsignedInteger('records_received')->default(0);
            $table->unsignedInteger('records_inserted')->default(0);
            $table->unsignedInteger('records_changed')->default(0);
            $table->unsignedInteger('records_skipped')->default(0);
            $table->unsignedInteger('records_failed')->default(0);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            // Last error summary, if any. Per-record errors live in sync_errors.
            $table->text('error_message')->nullable();

            // Arbitrary run metadata (job id, queue name, triggering user, etc.).
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['target_dataset', 'status']);
            $table->index(['external_source_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};
