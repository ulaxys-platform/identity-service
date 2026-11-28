<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * First-class authentication session. Device label, IP and user agent are
 * informational hashes; they never authenticate a request (ADR-0002).
 */
final class AuthSession extends Model
{
    use HasUlids;

    protected $table = 'identity_auth_sessions';

    protected $fillable = ['user_id', 'device_label', 'ip_hash', 'user_agent_hash', 'authentication_methods', 'mfa_completed_at', 'last_seen_at', 'expires_at', 'revoked_at', 'revoked_reason'];

    protected $casts = [
        'authentication_methods' => 'array',
        'mfa_completed_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function isUsable(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
