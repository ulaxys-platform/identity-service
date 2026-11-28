<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('identity_provider_mappings', function (Blueprint $table): void {
            $table->string('contact_email')->nullable();
            $table->ulid('requested_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('identity_provider_mappings', function (Blueprint $table): void {
            $table->dropColumn(['contact_email', 'requested_by']);
        });
    }
};
