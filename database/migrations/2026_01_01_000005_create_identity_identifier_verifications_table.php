<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_identifier_verifications', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('login_identifier_id')->constrained('identity_login_identifiers')->cascadeOnDelete();
            // SHA-256 of the delivered token; the raw token never touches storage.
            $table->string('token_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->unique('token_hash');
            $table->index('login_identifier_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_identifier_verifications');
    }
};
