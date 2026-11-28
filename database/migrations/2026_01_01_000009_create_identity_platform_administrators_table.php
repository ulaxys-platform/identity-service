<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Interim I1 administrative capability (ADR-0004): replaced by the
        // scoped RBAC engine in I3; no endpoint can create or revoke grants.
        Schema::create('identity_platform_administrators', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('identity_users')->cascadeOnDelete();
            $table->ulid('granted_by')->nullable();
            $table->string('grant_reason', 500)->nullable();
            $table->timestamp('granted_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_platform_administrators');
    }
};
