<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Single-use password reset / account setup challenge (hashed token only). */
final class CredentialChallenge extends Model
{
    use HasUlids;

    public const PURPOSE_PASSWORD_RESET = 'password_reset';

    public const PURPOSE_ACCOUNT_SETUP = 'account_setup';

    protected $table = 'identity_credential_challenges';

    protected $fillable = ['user_id', 'purpose', 'token_hash', 'expires_at', 'consumed_at', 'requested_ip_hash'];

    protected $casts = ['expires_at' => 'datetime', 'consumed_at' => 'datetime'];

    public function isUsable(): bool
    {
        return $this->consumed_at === null && $this->expires_at->isFuture();
    }
}
