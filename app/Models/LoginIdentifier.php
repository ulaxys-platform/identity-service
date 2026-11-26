<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Login identifier (email or username). Multiple identifiers may belong to
 * one user; uniqueness is enforced per (kind, normalized) in PostgreSQL.
 */
final class LoginIdentifier extends Model
{
    use HasUlids;

    public const KIND_EMAIL = 'email';

    public const KIND_USERNAME = 'username';

    public const KIND_VALUES = [self::KIND_EMAIL, self::KIND_USERNAME];

    protected $table = 'identity_login_identifiers';

    protected $fillable = ['user_id', 'kind', 'identifier', 'normalized', 'verified_at'];

    protected $casts = ['verified_at' => 'datetime'];

    public static function normalize(string $identifier): string
    {
        return mb_strtolower(trim($identifier));
    }

    /** Usernames are validated to never contain "@", so detection is unambiguous. */
    public static function kindFor(string $identifier): string
    {
        return str_contains($identifier, '@') ? self::KIND_EMAIL : self::KIND_USERNAME;
    }
}
