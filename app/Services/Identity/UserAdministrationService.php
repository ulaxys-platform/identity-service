<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Services\Identity;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Ulaxys\Identity\Models\LoginIdentifier;
use Ulaxys\Identity\Models\User;
use Ulaxys\Identity\Presenters\Mapper\Identity\UserMapper;
use Ulaxys\Identity\Repositories\RepositoryInterface\LoginIdentifierRepositoryInterface;
use Ulaxys\Identity\Repositories\RepositoryInterface\UserRepositoryInterface;
use Ulaxys\Identity\Transformers\ErrorResponseObjects\HttpErrorResponseObjectOfResult;
use Ulaxys\Identity\Transformers\Identity\UserCreatedResponse;
use Ulaxys\Identity\Transformers\Identity\UserListResponse;
use Ulaxys\Identity\Transformers\Identity\UserProfileResponse;

/**
 * Interim I1 user administration (ADR-0004): requires an active platform
 * administrator grant; creates invited users without passwords. PostgreSQL
 * remains the concurrency authority for identifier uniqueness.
 */
final class UserAdministrationService
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly LoginIdentifierRepositoryInterface $identifiers,
        private readonly UserMapper $mapper,
        private readonly AuditLogger $audit,
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
    public function create(User $actor, array $data): UserCreatedResponse|HttpErrorResponseObjectOfResult
    {
        $kind = (string) $data['kind'];
        $identifier = (string) $data['identifier'];
        $normalized = LoginIdentifier::normalize($identifier);
        if ($this->identifiers->findByKindAndNormalized($kind, $normalized) !== null) {
            return $this->identifierConflict();
        }
        try {
            $user = DB::transaction(function () use ($actor, $data, $kind, $identifier): User {
                $user = $this->users->createWithProfile(
                    ['status' => User::STATUS_INVITED, 'type' => User::TYPE_HUMAN, 'created_by' => (string) $actor->id],
                    [
                        'display_name' => (string) $data['displayName'],
                        'first_name' => $data['firstName'] ?? null,
                        'last_name' => $data['lastName'] ?? null,
                        'preferred_language' => (string) ($data['preferredLanguage'] ?? 'en'),
                        'preferred_timezone' => (string) ($data['preferredTimezone'] ?? 'UTC'),
                    ],
                );
                $this->identifiers->createForUser((string) $user->id, $kind, $identifier, false);
                $this->audit->audit('identity.user.created', (string) $actor->id, 'user', 'user', (string) $user->id, 'success', ['kind' => $kind]);

                return $user;
            });
        } catch (QueryException $exception) {
            // A concurrent transaction may win the unique race after the
            // domain precheck; PostgreSQL is the final authority.
            if ((string) $exception->getCode() !== '23505') {
                throw $exception;
            }

            return $this->identifierConflict();
        }

        return (new UserCreatedResponse)->setStatus(201)->setMessage('Created')->setSize(1)->withItem($this->mapper->map($user));
    }

    private function identifierConflict(): HttpErrorResponseObjectOfResult
    {
        return (new HttpErrorResponseObjectOfResult)->setStatus(409)->setCode(409)->setMessage((string) trans('identity.admin.identifier_conflict'));
    }
}
