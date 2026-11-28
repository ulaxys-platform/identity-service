<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Interim I1 administrative grant (ADR-0004); created only via bootstrap. */
final class PlatformAdministrator extends Model
{
    use HasUlids;

    protected $table = 'identity_platform_administrators';

    protected $fillable = ['user_id', 'granted_by', 'grant_reason', 'granted_at', 'revoked_at'];

    protected $casts = ['granted_at' => 'datetime', 'revoked_at' => 'datetime'];

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}
