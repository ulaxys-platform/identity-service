<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Append-only authentication lifecycle security record (ADR-0005). */
final class SecurityEvent extends Model
{
    use HasUlids;

    protected $table = 'identity_security_events';

    protected $fillable = ['event_type', 'user_id', 'session_id', 'result', 'ip_hash', 'context', 'occurred_at'];

    protected $casts = ['context' => 'array', 'occurred_at' => 'datetime'];
}
