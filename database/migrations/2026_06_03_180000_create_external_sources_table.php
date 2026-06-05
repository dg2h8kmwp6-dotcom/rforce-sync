<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_sources', function (Blueprint $table): void {
            $table->id();
            // Stable identifier — e.g. "rforce", "salesforce_prod", "salesforce_sandbox".
            $table->string('name')->unique();
            $table->string('label')->nullable();
            // Free-form metadata: gateway URL, API version, environment, etc.
            $table->json('config')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_sources');
    }
};
