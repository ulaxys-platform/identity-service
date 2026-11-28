<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Password reset and first-time account setup challenges.
        Schema::create('identity_credential_challenges', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('identity_users')->cascadeOnDelete();
            // password_reset | account_setup
            $table->string('purpose', 24);
            $table->string('token_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            // Keyed hash; the raw IP is never stored.
            $table->string('requested_ip_hash', 64)->nullable();
            $table->timestamps();
            $table->unique('token_hash');
            $table->index(['user_id', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_credential_challenges');
    }
};
