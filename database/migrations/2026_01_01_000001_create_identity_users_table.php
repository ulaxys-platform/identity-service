<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Stable opaque user identifier: email/username live in the login
        // identifiers table and are never used as primary identifiers.
        Schema::create('identity_users', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // Lifecycle: invited, pending_verification, active, suspended, disabled, archived.
            $table->string('status', 24)->default('invited');
            // human | service (service principals arrive in a later unit).
            $table->string('type', 16)->default('human');
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestamps();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_users');
    }
};
