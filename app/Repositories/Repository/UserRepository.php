<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Repositories\Repository;

use Ulaxys\Identity\Models\User;
use Ulaxys\Identity\Models\UserProfile;
use Ulaxys\Identity\Repositories\RepositoryInterface\UserRepositoryInterface;

/** All domain SQL and Eloquent access stays on the repository side. */
final class UserRepository implements UserRepositoryInterface
{
    public function findById(string $id): ?User
    {
        return User::query()->with('profile')->find($id);
    }

    public function page(int $limit, int $offset, ?string $status): array
    {
        $query = User::query()->with('profile')->orderBy('id');
        if ($status !== null) {
            $query->where('status', $status);
        }
        $total = (clone $query)->count();
        $users = $query->skip($offset)->take($limit)->get()->all();

        return [$users, $total];
    }

    public function createWithProfile(array $userAttributes, array $profileAttributes): User
    {
        $user = User::query()->create($userAttributes);
        $user->profile()->create($profileAttributes);

        return $user->refresh();
    }

    public function setStatus(string $userId, string $status, ?string $actorId): void
    {
        User::query()->whereKey($userId)->update(['status' => $status, 'updated_by' => $actorId]);
    }

    public function updateProfile(string $userId, array $profileAttributes): void
    {
        if ($profileAttributes === []) {
            return;
        }
        UserProfile::query()->where('user_id', $userId)->update($profileAttributes);
    }
}
