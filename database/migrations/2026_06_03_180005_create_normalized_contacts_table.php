<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('normalized_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('external_source_id')
                ->constrained('external_sources')
                ->cascadeOnDelete();
            $table->string('external_id');

            // Soft FK by external id; resolved at query time. We don't add a hard FK
            // because contacts can arrive before their account row.
            $table->string('account_external_id')->nullable();

            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('title')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('mobile_phone')->nullable();

            $table->string('mailing_street')->nullable();
            $table->string('mailing_city')->nullable();
            $table->string('mailing_state')->nullable();
            $table->string('mailing_postal_code')->nullable();
            $table->string('mailing_country')->nullable();

            $table->string('owner_external_id')->nullable();
            $table->timestamp('external_created_at')->nullable();
            $table->timestamp('external_modified_at')->nullable();
            $table->json('extra')->nullable();

            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['external_source_id', 'external_id'], 'norm_contacts_source_external_unique');
            $table->index('account_external_id');
            $table->index('email');
            $table->index('external_modified_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('normalized_contacts');
    }
};
