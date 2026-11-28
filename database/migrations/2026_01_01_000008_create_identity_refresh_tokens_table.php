<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Refresh secrets live only as SHA-256 hashes; every presented token
        // is single-use within a rotation family rooted at one session.
        Schema::create('identity_refresh_tokens', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('session_id')->constrained('identity_auth_sessions')->cascadeOnDelete();
            $table->ulid('user_id');
            $table->ulid('family_id');
            $table->string('token_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 64)->nullable();
            $table->timestamps();
            $table->unique('token_hash');
            $table->index('family_id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_refresh_tokens');
    }
};
