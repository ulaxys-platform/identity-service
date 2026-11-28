<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Services\Identity;

use Illuminate\Support\Str;
use Ulaxys\Identity\Models\User;
use Ulaxys\Identity\Presenters\Mapper\Identity\UserMapper;
use Ulaxys\Identity\Repositories\RepositoryInterface\UserRepositoryInterface;
use Ulaxys\Identity\Services\Identity\Provider\ProviderFailure;
use Ulaxys\Identity\Services\Identity\Provider\ProvisioningConflict;
use Ulaxys\Identity\Services\Identity\Provider\ProvisionProviderIdentity;
use Ulaxys\Identity\Transformers\ErrorResponseObjects\HttpErrorResponseObjectOfResult;
use Ulaxys\Identity\Transformers\Identity\UserCreatedResponse;
use Ulaxys\Identity\Transformers\Identity\UserListResponse;
use Ulaxys\Identity\Transformers\Identity\UserProfileResponse;

/** Identity profile administration behind the interim platform administrator grant. */
final class UserAdministrationService
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly UserMapper $mapper,
        private readonly ProvisionProviderIdentity $provisioning,
    ) {}

    public function page(int $limit, int $offset, ?string $status): UserListResponse
    {
        [$users, $total] = $this->users->page($limit, $offset, $status);
        $items = array_map(fn (User $user) => $this->mapper->map($user), $users);

        return (new UserListResponse)
            ->setStatus(200)->setMessage('OK')->setSize(count($items))
            ->withPage($items, $limit, $offset, $total);
    }

    public function show(string $userId): UserProfileResponse|HttpErrorResponseObjectOfResult
    {
        $user = $this->users->findById($userId);
        if ($user === null) {
            return (new HttpErrorResponseObjectOfResult)->setStatus(404)->setCode(404)->setMessage('Resource not found.');
        }

        return (new UserProfileResponse)->setStatus(200)->setMessage('OK')->setSize(1)->withItem($this->mapper->map($user));
    }

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data, string $requestId): UserCreatedResponse|HttpErrorResponseObjectOfResult
    {
        if (! Str::isUlid($requestId)) {
            return (new HttpErrorResponseObjectOfResult)->setStatus(400)->setCode(400)->setMessage('Idempotency-Key must be a provisioning request ULID.');
        }

        try {
            $id = $this->provisioning->stage($requestId, [
                'display_name' => $data['displayName'],
                'first_name' => $data['firstName'] ?? null,
                'last_name' => $data['lastName'] ?? null,
                'preferred_language' => $data['preferredLanguage'] ?? 'en',
                'preferred_timezone' => $data['preferredTimezone'] ?? 'UTC',
            ], $data['identifier'], (string) $actor->id);
        } catch (ProvisioningConflict) {
            return (new HttpErrorResponseObjectOfResult)->setStatus(409)->setCode(409)->setMessage('Provisioning request conflict.');
        } catch (ProviderFailure) {
            return (new HttpErrorResponseObjectOfResult)->setStatus(503)->setCode(503)->setMessage('Provisioning pending. Retry with the same key.');
        }

        return (new UserCreatedResponse)->setStatus(201)->setMessage('Created')->setSize(1)
            ->withItem($this->mapper->map($this->users->findById($id)));
    }
}
