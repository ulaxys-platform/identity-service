<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only (ADR-0005): authentication lifecycle security events.
        Schema::create('identity_security_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('event_type', 96);
            $table->ulid('user_id')->nullable();
            $table->ulid('session_id')->nullable();
            $table->string('result', 16)->default('success');
            $table->string('ip_hash', 64)->nullable();
            $table->jsonb('context')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index('event_type');
            $table->index('user_id');
            $table->index('session_id');
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_security_events');
    }
};
