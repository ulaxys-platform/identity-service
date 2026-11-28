<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only (ADR-0005): application code never updates or deletes rows.
        Schema::create('identity_audit_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('actor_id')->nullable();
            // user | service | system | operator
            $table->string('actor_type', 24)->default('system');
            $table->string('action', 96);
            $table->string('subject_type', 64)->nullable();
            $table->ulid('subject_id')->nullable();
            $table->string('result', 16)->default('success');
            $table->string('correlation_id', 64)->nullable();
            $table->jsonb('context')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index('actor_id');
            $table->index('subject_id');
            $table->index(['action', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_audit_events');
    }
};
