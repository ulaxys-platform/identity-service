<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Argon2id hash container (ADR-0003); the hash is hidden from serialization. */
final class PasswordCredential extends Model
{
    use HasUlids;

    protected $table = 'identity_password_credentials';

    protected $fillable = ['user_id', 'password_hash', 'algorithm', 'version', 'must_change', 'last_changed_at'];

    protected $hidden = ['password_hash'];

    protected $casts = ['version' => 'integer', 'must_change' => 'boolean', 'last_changed_at' => 'datetime'];
}
