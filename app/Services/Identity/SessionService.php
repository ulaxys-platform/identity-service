<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Services\Identity;

use Illuminate\Support\Facades\DB;
use Ulaxys\Identity\Models\AuthSession;
use Ulaxys\Identity\Models\User;
use Ulaxys\Identity\Presenters\Mapper\Identity\AuthSessionMapper;
use Ulaxys\Identity\Repositories\RepositoryInterface\AuthSessionRepositoryInterface;
use Ulaxys\Identity\Repositories\RepositoryInterface\RefreshTokenRepositoryInterface;
use Ulaxys\Identity\Transformers\ErrorResponseObjects\HttpErrorResponseObjectOfResult;
use Ulaxys\Identity\Transformers\HttpSuccessResponseObject;
use Ulaxys\Identity\Transformers\Identity\SessionListResponse;
use Ulaxys\Identity\Transformers\ResponseObject;

/** Owner-scoped session lifecycle: list, revoke one, revoke all others. */
final class SessionService
{
    public function __construct(
        private readonly AuthSessionRepositoryInterface $sessions,
        private readonly RefreshTokenRepositoryInterface $refreshTokens,
        private readonly AuthSessionMapper $mapper,
        private readonly AuditLogger $audit,
    ) {}

    public function listForUser(User $user, AuthSession $currentSession): SessionListResponse
    {
        $items = array_map(
            fn (AuthSession $session) => $this->mapper->map($session, (string) $session->id === (string) $currentSession->id),
            $this->sessions->usableSessionsForUser((string) $user->id),
        );

        return (new SessionListResponse)
            ->setStatus(200)->setMessage('OK')->setSize(count($items))
            ->withItems($items);
    }

    public function revoke(User $user, string $sessionId): ResponseObject
    {
        $target = $this->sessions->findById($sessionId);
        // IDOR-safe: another user's session is indistinguishable from a
        // nonexistent one.
        if ($target === null || (string) $target->user_id !== (string) $user->id) {
            return (new HttpErrorResponseObjectOfResult)->setStatus(404)->setCode(404)->setMessage('Resource not found.');
        }
        DB::transaction(function () use ($user, $target): void {
            $this->sessions->revoke((string) $target->id, 'revoked_by_owner');
            $this->refreshTokens->revokeForSession((string) $target->id, 'revoked_by_owner');
            $this->audit->audit('identity.session.revoke', (string) $user->id, 'user', 'auth_session', (string) $target->id, 'success');
            $this->audit->security('auth.session.revoked', (string) $user->id, (string) $target->id, 'success', ['reason' => 'revoked_by_owner']);
        });

        return (new HttpSuccessResponseObject)->setStatus(200)->setMessage('Session revoked.');
    }

    public function revokeCurrent(User $user, AuthSession $currentSession): HttpSuccessResponseObject
    {
        DB::transaction(function () use ($user, $currentSession): void {
            $this->sessions->revoke((string) $currentSession->id, 'logout');
            $this->refreshTokens->revokeForSession((string) $currentSession->id, 'logout');
            $this->audit->audit('identity.session.revoke_current', (string) $user->id, 'user', 'auth_session', (string) $currentSession->id, 'success');
            $this->audit->security('auth.session.revoked', (string) $user->id, (string) $currentSession->id, 'success', ['reason' => 'logout']);
        });

        return (new HttpSuccessResponseObject)->setStatus(200)->setMessage('Session revoked.');
    }

    public function revokeAllOthers(User $user, AuthSession $currentSession): HttpSuccessResponseObject
    {
        DB::transaction(function () use ($user, $currentSession): void {
            $count = $this->sessions->revokeAllForUser((string) $user->id, (string) $currentSession->id, 'revoked_all_others');
            $this->refreshTokens->revokeForUser((string) $user->id, (string) $currentSession->id, 'revoked_all_others');
            $this->audit->audit('identity.session.revoke_all_others', (string) $user->id, 'user', 'auth_session', (string) $currentSession->id, 'success', ['revoked_sessions' => $count]);
            $this->audit->security('auth.session.revoked_all', (string) $user->id, (string) $currentSession->id, 'success', ['revoked_sessions' => $count]);
        });

        return (new HttpSuccessResponseObject)->setStatus(200)->setMessage('Other sessions revoked.');
    }
}
