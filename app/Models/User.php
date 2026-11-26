<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Authentication identity root. Commercial identity (Business Partner,
 * master/operational accounts) is owned by other services and is never
 * modelled here. The ULID primary key is the stable opaque subject.
 */
final class User extends Model
{
    use HasUlids;

    public const STATUS_INVITED = 'invited';

    public const STATUS_PENDING_VERIFICATION = 'pending_verification';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_DISABLED = 'disabled';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUS_VALUES = [
        self::STATUS_INVITED,
        self::STATUS_PENDING_VERIFICATION,
        self::STATUS_ACTIVE,
        self::STATUS_SUSPENDED,
        self::STATUS_DISABLED,
        self::STATUS_ARCHIVED,
    ];

    public const TYPE_HUMAN = 'human';

    public const TYPE_SERVICE = 'service';

    protected $table = 'identity_users';

    protected $fillable = ['status', 'type', 'created_by', 'updated_by'];

    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class, 'user_id');
    }

    /** Login eligibility: local authentication requires an active human account. */
    public function isLoginEligible(): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->type === self::TYPE_HUMAN;
    }
}
