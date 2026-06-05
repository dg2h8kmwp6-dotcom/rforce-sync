<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('query_definitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('external_source_id')
                ->constrained('external_sources')
                ->cascadeOnDelete();

            $table->string('name')->unique();
            $table->string('description', 500)->nullable();

            $table->text('soql');

            // Logical bucket the resulting records belong to. Drives raw_rforce_records.object_type
            // and the transformer lookup. Free-form (Account, Contact, ...; also custom objects).
            $table->string('target_object')->nullable();

            // Optional class name of an App\Services\RForce\Transformers\* implementation.
            // When null, the system falls back to config('rforce.objects.{target_object}.transformer').
            $table->string('transformer_class')->nullable();

            $table->boolean('enabled')->default(true);

            // Cron expression. Null means "manual only".
            $table->string('schedule')->nullable();

            $table->timestamp('last_run_at')->nullable();

            $table->timestamps();

            $table->index(['enabled', 'schedule']);
            $table->index('target_object');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('query_definitions');
    }
};
