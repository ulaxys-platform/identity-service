<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Append-only administrative audit record (ADR-0005). */
final class AuditEvent extends Model
{
    use HasUlids;

    protected $table = 'identity_audit_events';

    protected $fillable = ['actor_id', 'actor_type', 'action', 'subject_type', 'subject_id', 'result', 'correlation_id', 'context', 'occurred_at'];

    protected $casts = ['context' => 'array', 'occurred_at' => 'datetime'];
}
