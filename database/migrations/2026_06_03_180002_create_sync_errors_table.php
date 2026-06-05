<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_errors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sync_run_id')
                ->constrained('sync_runs')
                ->cascadeOnDelete();

            // The external object/record this error pertains to (when known).
            $table->string('object_type')->nullable();
            $table->string('external_id')->nullable();

            // Classification (e.g. http_4xx, http_5xx, transform, validation, auth).
            $table->string('category')->nullable();
            $table->text('message');

            // Full context: payload snippet, request id, stack trace, etc.
            $table->json('context')->nullable();

            $table->timestamps();

            $table->index(['sync_run_id', 'category']);
            $table->index(['object_type', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_errors');
    }
};
