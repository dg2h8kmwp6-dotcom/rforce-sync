<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('normalized_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('external_source_id')
                ->constrained('external_sources')
                ->cascadeOnDelete();
            $table->string('external_id');

            $table->string('name')->nullable();
            $table->string('account_number')->nullable();
            $table->string('industry')->nullable();
            $table->string('type')->nullable();
            $table->string('website')->nullable();
            $table->string('phone')->nullable();

            // Billing address (denormalized for read simplicity).
            $table->string('billing_street')->nullable();
            $table->string('billing_city')->nullable();
            $table->string('billing_state')->nullable();
            $table->string('billing_postal_code')->nullable();
            $table->string('billing_country')->nullable();

            $table->decimal('annual_revenue', 18, 2)->nullable();
            $table->unsignedInteger('employee_count')->nullable();

            $table->string('owner_external_id')->nullable();
            $table->timestamp('external_created_at')->nullable();
            $table->timestamp('external_modified_at')->nullable();

            // Anything not yet promoted to a column lives here. Lets us add
            // first-class columns later without losing data in the interim.
            $table->json('extra')->nullable();

            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['external_source_id', 'external_id'], 'norm_accounts_source_external_unique');
            $table->index('name');
            $table->index('external_modified_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('normalized_accounts');
    }
};
