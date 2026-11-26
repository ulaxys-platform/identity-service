<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Refresh token rotation record. The presented secret is stored only as a
 * SHA-256 hash; presenting a consumed or revoked row revokes its family.
 */
final class RefreshToken extends Model
{
    use HasUlids;

    protected $table = 'identity_refresh_tokens';

    protected $fillable = ['session_id', 'user_id', 'family_id', 'token_hash', 'expires_at', 'consumed_at', 'revoked_at', 'revoked_reason'];

    protected $casts = ['expires_at' => 'datetime', 'consumed_at' => 'datetime', 'revoked_at' => 'datetime'];

    public function isUsable(): bool
    {
        return $this->consumed_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
