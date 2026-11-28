<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_provider_mappings', function (Blueprint $table): void {
            $table->ulid('request_id')->primary();
            $table->foreignUlid('user_id')->nullable()->unique()->constrained('identity_users')->restrictOnDelete();
            $table->string('issuer');
            $table->string('subject')->nullable();
            $table->string('state', 24);
            $table->timestamps();
            $table->unique(['issuer', 'subject']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_provider_mappings');
    }
};
