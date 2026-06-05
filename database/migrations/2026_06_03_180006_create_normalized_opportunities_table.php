<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('normalized_opportunities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('external_source_id')
                ->constrained('external_sources')
                ->cascadeOnDelete();
            $table->string('external_id');

            $table->string('account_external_id')->nullable();
            $table->string('name')->nullable();
            $table->string('stage')->nullable();
            $table->string('type')->nullable();
            $table->decimal('amount', 18, 2)->nullable();
            $table->unsignedTinyInteger('probability')->nullable();
            $table->date('close_date')->nullable();
            $table->boolean('is_won')->nullable();
            $table->boolean('is_closed')->nullable();
            $table->string('forecast_category')->nullable();

            $table->string('owner_external_id')->nullable();
            $table->timestamp('external_created_at')->nullable();
            $table->timestamp('external_modified_at')->nullable();
            $table->json('extra')->nullable();

            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['external_source_id', 'external_id'], 'norm_opps_source_external_unique');
            $table->index('account_external_id');
            $table->index('stage');
            $table->index('close_date');
            $table->index('external_modified_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('normalized_opportunities');
    }
};
