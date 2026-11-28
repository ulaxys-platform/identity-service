<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Signing key metadata; PEM material lives on secure storage outside VCS. */
final class SigningKey extends Model
{
    use HasUlids;

    protected $table = 'identity_signing_keys';

    protected $fillable = ['kid', 'algorithm', 'private_key_path', 'public_key_path', 'is_active', 'activated_at', 'retired_at'];

    protected $casts = ['is_active' => 'boolean', 'activated_at' => 'datetime', 'retired_at' => 'datetime'];
}
