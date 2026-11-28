<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Metadata only: PEM material lives on secure storage outside VCS.
        Schema::create('identity_signing_keys', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // RFC 7638 thumbprint of the public key.
            $table->string('kid', 64)->unique();
            $table->string('algorithm', 16)->default('RS256');
            $table->string('private_key_path', 500);
            $table->string('public_key_path', 500);
            $table->boolean('is_active')->default(false);
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_signing_keys');
    }
};
