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

            // Business-named dataset this query feeds. Drives the transformer
            // lookup (config('rforce.datasets.{target_dataset}.transformer'))
            // and tags the resulting raw rows. Free-form so custom datasets
            // are a config-only addition.
            $table->string('target_dataset')->nullable();

            // Optional explicit class name of an
            // App\Services\RForce\Transformers\* implementation. When null
            // the system falls back to the dataset map in config/rforce.php.
            $table->string('transformer_class')->nullable();

            $table->boolean('enabled')->default(true);

            // Cron expression. Null means "manual only".
            $table->string('schedule')->nullable();

            $table->timestamp('last_run_at')->nullable();

            $table->timestamps();

            $table->index(['enabled', 'schedule']);
            $table->index('target_dataset');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('query_definitions');
    }
};
