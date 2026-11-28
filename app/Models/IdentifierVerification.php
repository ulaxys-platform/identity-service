<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Single-use email verification challenge for one login identifier. */
final class IdentifierVerification extends Model
{
    use HasUlids;

    protected $table = 'identity_identifier_verifications';

    protected $fillable = ['login_identifier_id', 'token_hash', 'expires_at', 'consumed_at'];

    protected $casts = ['expires_at' => 'datetime', 'consumed_at' => 'datetime'];

    public function isUsable(): bool
    {
        return $this->consumed_at === null && $this->expires_at->isFuture();
    }
}
