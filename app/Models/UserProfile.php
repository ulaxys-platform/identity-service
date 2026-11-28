<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class UserProfile extends Model
{
    use HasUlids;

    protected $table = 'identity_user_profiles';

    protected $fillable = ['user_id', 'display_name', 'first_name', 'last_name', 'preferred_language', 'preferred_timezone'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
