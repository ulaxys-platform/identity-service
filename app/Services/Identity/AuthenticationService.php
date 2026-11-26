<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Services\Identity;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;
use Ulaxys\Identity\Models\LoginIdentifier;
use Ulaxys\Identity\Models\User;
use Ulaxys\Identity\Repositories\RepositoryInterface\AuthSessionRepositoryInterface;
use Ulaxys\Identity\Repositories\RepositoryInterface\LoginIdentifierRepositoryInterface;
use Ulaxys\Identity\Repositories\RepositoryInterface\PasswordCredentialRepositoryInterface;
use Ulaxys\Identity\Repositories\RepositoryInterface\RefreshTokenRepositoryInterface;
use Ulaxys\Identity\Repositories\RepositoryInterface\UserRepositoryInterface;
use Ulaxys\Identity\Services\Identity\Tokens\SessionGrant;
use Ulaxys\Identity\Services\Identity\Tokens\TokenException;
use Ulaxys\Identity\Transformers\ErrorResponseObjects\HttpErrorResponseObjectOfResult;
use Ulaxys\Identity\Transformers\HttpSuccessResponseObject;
use Ulaxys\Identity\Transformers\Identity\LoginResponse;
use Ulaxys\Identity\Transformers\Identity\TokenRefreshResponse;

/**
 * Local authentication orchestrator (ADR-0001/0003). Unknown identifiers,
 * missing credentials and wrong passwords produce identical responses; a
 * dummy Argon2id verification equalizes timing when no credential exists.
 */
final class AuthenticationService
{
    private ?string $dummyHash = null;

    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly LoginIdentifierRepositoryInterface $identifiers,
        private readonly PasswordCredentialRepositoryInterface $credentials,
        private readonly AuthSessionRepositoryInterface $sessions,
        private readonly RefreshTokenRepositoryInterface $refreshTokens,
        private readonly TokenService $tokens,
        private readonly AuditLogger $audit,
        private readonly ActorContextService $actorContext,
    ) {}

    public function login(string $identifier, string $password, ?string $deviceLabel, ?string $ip, ?string $userAgent, string $correlationId): LoginResponse|HttpErrorResponseObjectOfResult
    {
        $kind = LoginIdentifier::kindFor($identifier);
        $normalized = LoginIdentifier::normalize($identifier);
        $ipHash = $ip === null ? null : AuditLogger::hashWithPepper($ip);
        $userAgentHash = $userAgent === null ? null : AuditLogger::hashWithPepper($userAgent);
        $identifierRecord = $this->identifiers->findByKindAndNormalized($kind, $normalized);
        $user = $identifierRecord === null ? null : $this->users->findById((string) $identifierRecord->user_id);
        $credential = $user === null ? null : $this->credentials->findByUserId((string) $user->id);
        if ($identifierRecord === null || $user === null || $credential === null) {
            // Timing equalization: never skip the Argon2id verification path.
            password_verify($password, $this->dummyHash());
            $this->audit->security('auth.login.failure', $user?->id, null, 'failure', ['reason' => 'unknown_identifier_or_credential'], $ipHash);

            return $this->invalidCredentials();
        }
        if (! Hash::check($password, $credential->password_hash)) {
            $this->audit->security('auth.login.failure', (string) $user->id, null, 'failure', ['reason' => 'invalid_password'], $ipHash);

            return $this->invalidCredentials();
        }
        if (! $user->isLoginEligible()) {
            $this->audit->security('auth.login.failure', (string) $user->id, null, 'failure', ['reason' => 'account_not_active', 'status' => $user->status], $ipHash);

            return $this->invalidCredentials();
        }
        // Opportunistic rehash upgrade per ADR-0003.
        if (Hash::needsRehash($credential->password_hash)) {
            $this->credentials->updateHash((string) $user->id, Hash::make($password));
        }
        try {
            return DB::transaction(function () use ($user, $deviceLabel, $ipHash, $userAgentHash, $correlationId): LoginResponse {
                $session = $this->sessions->create([
                    'user_id' => (string) $user->id,
                    'device_label' => $deviceLabel,
                    'ip_hash' => $ipHash,
                    'user_agent_hash' => $userAgentHash,
                    'authentication_methods' => ['password'],
                    'last_seen_at' => now(),
                    'expires_at' => now()->addHours(max(1, (int) config('identity.session.lifetime_hours', 12))),
                ]);
                $issued = $this->tokens->issueForSession($user, $session);
                $this->audit->audit('identity.auth.login', (string) $user->id, 'user', 'auth_session', (string) $session->id, 'success', ['amr' => ['password']], $correlationId);
                $this->audit->security('auth.login.success', (string) $user->id, (string) $session->id, 'success', [], $ipHash);

                return (new LoginResponse)
                    ->setStatus(200)->setMessage('OK')->setSize(1)
                    ->withGrant($issued->grant, $this->actorContext->forActor($user, $session));
            });
        } catch (TokenException $exception) {
            if ($exception->reason === TokenException::KEYS_UNAVAILABLE) {
                // Signing keys unavailable: fail closed without session residue.
                $this->audit->security('auth.login.failure', (string) $user->id, null, 'failure', ['reason' => 'signing_keys_unavailable'], $ipHash);

                return (new HttpErrorResponseObjectOfResult)
                    ->setStatus(503)->setCode(503)->setMessage('Authentication dependency unavailable.');
            }
            throw $exception;
        } catch (Throwable $exception) {
            throw $exception;
        }
    }

    public function refresh(string $refreshToken, ?string $ip): TokenRefreshResponse|HttpErrorResponseObjectOfResult
    {
        $ipHash = $ip === null ? null : AuditLogger::hashWithPepper($ip);
        try {
            $issued = $this->tokens->rotate($refreshToken);
        } catch (TokenException $exception) {
            $reason = $exception->reason === TokenException::REUSE_DETECTED ? 'refresh_token_reuse' : 'invalid_refresh_token';
            $this->audit->security('auth.token.refresh.failure', null, null, 'failure', ['reason' => $reason], $ipHash);

            return (new HttpErrorResponseObjectOfResult)
                ->setStatus(401)->setCode(401)->setMessage((string) trans('identity.auth.invalid_refresh_token'));
        }

        return (new TokenRefreshResponse)
            ->setStatus(200)->setMessage('OK')->setSize(1)
            ->withGrant($issued->grant, $this->actorContext->forActor($issued->user, $issued->session));
    }

    public function logout(User $user, AuthSession $session): HttpSuccessResponseObject
    {
        DB::transaction(function () use ($user, $session): void {
            $this->sessions->revoke((string) $session->id, 'logout');
            $this->refreshTokens->revokeForSession((string) $session->id, 'logout');
            $this->audit->audit('identity.auth.logout', (string) $user->id, 'user', 'auth_session', (string) $session->id, 'success');
            $this->audit->security('auth.session.revoked', (string) $user->id, (string) $session->id, 'success', ['reason' => 'logout']);
        });

        return (new HttpSuccessResponseObject)->setStatus(200)->setMessage('Logged out.');
    }

    private function invalidCredentials(): HttpErrorResponseObjectOfResult
    {
        return (new HttpErrorResponseObjectOfResult)
            ->setStatus(401)->setCode(401)->setMessage((string) trans('identity.auth.invalid_credentials'));
    }

    private function dummyHash(): string
    {
        if ($this->dummyHash === null) {
            $driver = strtoupper((string) config('hashing.driver', 'argon2id'));
            $algorithm = defined('PASSWORD_'.$driver) ? constant('PASSWORD_'.$driver) : PASSWORD_DEFAULT;
            $this->dummyHash = (string) password_hash('ulaxys-identity-dummy-verification-target', $algorithm);
        }

        return $this->dummyHash;
    }
}
