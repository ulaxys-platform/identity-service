<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Presenters\Mapper\Identity;

use Ulaxys\Identity\Dto\Identity\UserDto;
use Ulaxys\Identity\Models\User;

/** Copies model fields into the typed DTO; the model never escapes. */
final class UserMapper
{
    public function map(User $user): UserDto
    {
        $profile = $user->profile;
        $dto = new UserDto;
        $dto->id = (string) $user->id;
        $dto->status = (string) $user->status;
        $dto->type = (string) $user->type;
        $dto->displayName = (string) ($profile?->display_name ?? '');
        $dto->firstName = $profile?->first_name;
        $dto->lastName = $profile?->last_name;
        $dto->preferredLanguage = (string) ($profile?->preferred_language ?? 'en');
        $dto->preferredTimezone = (string) ($profile?->preferred_timezone ?? 'UTC');
        $dto->createdAt = (string) $user->created_at?->toIso8601String();
        $dto->updatedAt = (string) $user->updated_at?->toIso8601String();

        return $dto;
    }
}
