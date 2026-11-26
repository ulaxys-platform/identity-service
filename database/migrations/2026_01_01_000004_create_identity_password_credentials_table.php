<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_password_credentials', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('identity_users')->cascadeOnDelete();
            // Argon2id output only; plaintext is never persisted or logged.
            $table->string('password_hash', 255);
            $table->string('algorithm', 32)->default('argon2id');
            $table->unsignedInteger('version')->default(1);
            $table->boolean('must_change')->default(false);
            $table->timestamp('last_changed_at')->nullable();
            $table->timestamps();
            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_password_credentials');
    }
};
