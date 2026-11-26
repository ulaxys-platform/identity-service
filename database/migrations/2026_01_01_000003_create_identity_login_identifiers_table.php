<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_login_identifiers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('identity_users')->cascadeOnDelete();
            // email | username
            $table->string('kind', 16);
            // Exactly as provided; `normalized` is the case-folded lookup value.
            $table->string('identifier', 255);
            $table->string('normalized', 255);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->unique(['kind', 'normalized']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_login_identifiers');
    }
};
