<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Services\Identity;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Ulaxys\Identity\Models\AuthSession;
use Ulaxys\Identity\Models\CredentialChallenge;
use Ulaxys\Identity\Models\LoginIdentifier;
use Ulaxys\Identity\Models\User;
use Ulaxys\Identity\Repositories\RepositoryInterface\AuthSessionRepositoryInterface;
use Ulaxys\Identity\Repositories\RepositoryInterface\CredentialChallengeRepositoryInterface;
use Ulaxys\Identity\Repositories\RepositoryInterface\LoginIdentifierRepositoryInterface;
use Ulaxys\Identity\Repositories\RepositoryInterface\PasswordCredentialRepositoryInterface;
use Ulaxys\Identity\Repositories\RepositoryInterface\RefreshTokenRepositoryInterface;
use Ulaxys\Identity\Repositories\RepositoryInterface\UserRepositoryInterface;
use Ulaxys\Identity\Services\Identity\Delivery\DeliveryMessage;
use Ulaxys\Identity\Services\Identity\Delivery\TokenDeliveryInterface;
use Ulaxys\Identity\Transformers\ErrorResponseObjects\HttpErrorResponseObjectOfResult;
use Ulaxys\Identity\Transformers\HttpSuccessResponseObject;
use Ulaxys\Identity\Transformers\ResponseObject;

/**
 * Credential lifecycle: password change, enumeration-safe recovery, account
 * setup via bootstrap token, and email verification. One-time tokens are
 * stored only as SHA-256 hashes and delivered through TokenDeliveryInterface.
 */
final class CredentialService
{
    public function __construct(
        private readonly PasswordCredentialRepositoryInterface $credentials,
        private readonly AuthSessionRepositoryInterface $sessions,
        private readonly RefreshTokenRepositoryInterface $refreshTokens,
        private readonly CredentialChallengeRepositoryInterface $challenges,
        private readonly UserRepositoryInterface $users,
        private readonly LoginIdentifierRepositoryInterface $identifiers,
        private readonly PasswordPolicy $policy,
        private readonly AuditLogger $audit,
        private readonly TokenDeliveryInterface $delivery,
    ) {}

    public function changePassword(User $user, AuthSession $currentSession, string $currentPassword, string $newPassword): ResponseObject
    {
        $credential = $this->credentials->findByUserId((string) $user->id);
        if ($credential === null || ! Hash::check($currentPassword, $credential->password_hash)) {
            return (new HttpErrorResponseObjectOfResult)->setStatus(400)->setCode(400)->setMessage((string) trans('identity.credentials.current_password_invalid'));
        }
        $violations = $this->policy->violations($newPassword, $this->identifierSeeds((string) $user->id, $user));
        if ($violations !== []) {
            return $this->policyViolation($violations);
        }
        DB::transaction(function () use ($user, $currentSession, $newPassword): void {
            $this->credentials->updateHash((string) $user->id, Hash::make($newPassword));
            $this->sessions->revokeAllForUser((string) $user->id, (string) $currentSession->id, 'password_changed');
            $this->refreshTokens->revokeForUser((string) $user->id, (string) $currentSession->id, 'password_changed');
            $this->audit->audit('identity.credential.password_changed', (string) $user->id, 'user', 'user', (string) $user->id, 'success');
            $this->audit->security('auth.credential.changed', (string) $user->id, (string) $currentSession->id, 'success', ['reason' => 'password_change']);
        });

        return (new HttpSuccessResponseObject)->setStatus(200)->setMessage((string) trans('identity.credentials.password_changed'));
    }

    public function forgotPassword(string $identifier, ?string $ip): HttpSuccessResponseObject
    {
        $kind = LoginIdentifier::kindFor($identifier);
        $normalized = LoginIdentifier::normalize($identifier);
        $ipHash = $ip === null ? null : AuditLogger::hashWithPepper($ip);
        $identifierRecord = $this->identifiers->findByKindAndNormalized($kind, $normalized);
        $user = $identifierRecord === null ? null : $this->users->findById((string) $identifierRecord->user_id);
        $credential = $user === null ? null : $this->credentials->findByUserId((string) $user->id);
        // The response never reveals whether the account exists (ADR-0003).
        if ($identifierRecord !== null && $user !== null && $credential !== null && $user->isLoginEligible()) {
            $token = $this->generateChallengeToken();
            $this->challenges->create([
                'user_id' => (string) $user->id,
                'purpose' => CredentialChallenge::PURPOSE_PASSWORD_RESET,
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addMinutes(max(5, (int) config('identity.challenge.reset_ttl_minutes', 60))),
                'requested_ip_hash' => $ipHash,
            ]);
            $this->delivery->deliver(new DeliveryMessage(
                purpose: 'password_reset',
                toIdentifier: (string) $identifierRecord->identifier,
                toIdentifierKind: $kind,
                token: $token,
                locale: (string) app()->getLocale(),
            ));
            $this->audit->security('auth.recovery.requested', (string) $user->id, null, 'success', ['purpose' => 'password_reset'], $ipHash);
        } else {
            $this->audit->security('auth.recovery.requested', null, null, 'success', ['purpose' => 'password_reset', 'matched' => false], $ipHash);
        }

        return (new HttpSuccessResponseObject)->setStatus(200)->setMessage((string) trans('identity.credentials.recovery_requested'));
    }

    public function resetPassword(string $token, string $newPassword): ResponseObject
    {
        $challenge = $this->challenges->findByTokenHash(hash('sha256', $token));
        if ($challenge === null || (string) $challenge->purpose !== CredentialChallenge::PURPOSE_PASSWORD_RESET || ! $challenge->isUsable()) {
            return $this->challengeInvalid();
        }
        $user = $this->users->findById((string) $challenge->user_id);
        if ($user === null) {
            return $this->challengeInvalid();
        }
        $violations = $this->policy->violations($newPassword, $this->identifierSeeds((string) $user->id, $user));
        if ($violations !== []) {
            return $this->policyViolation($violations);
        }
        DB::transaction(function () use ($challenge, $user, $newPassword): void {
            if ($this->credentials->findByUserId((string) $user->id) === null) {
                $this->credentials->createForUser((string) $user->id, Hash::make($newPassword));
            } else {
                $this->credentials->updateHash((string) $user->id, Hash::make($newPassword));
            }
            $this->challenges->consume((string) $challenge->id);
            $this->sessions->revokeAllForUser((string) $user->id, null, 'password_reset');
            $this->refreshTokens->revokeForUser((string) $user->id, null, 'password_reset');
            $this->audit->audit('identity.credential.password_reset', (string) $user->id, 'user', 'user', (string) $user->id, 'success');
            $this->audit->security('auth.credential.reset', (string) $user->id, null, 'success', []);
        });

        return (new HttpSuccessResponseObject)->setStatus(200)->setMessage((string) trans('identity.credentials.recovery_completed'));
    }

    public function setupAccount(string $token, string $newPassword): ResponseObject
    {
        $challenge = $this->challenges->findByTokenHash(hash('sha256', $token));
        if ($challenge === null || (string) $challenge->purpose !== CredentialChallenge::PURPOSE_ACCOUNT_SETUP || ! $challenge->isUsable()) {
            return $this->challengeInvalid();
        }
        $user = $this->users->findById((string) $challenge->user_id);
        if ($user === null || $this->credentials->findByUserId((string) $user->id) !== null) {
            return $this->challengeInvalid();
        }
        $violations = $this->policy->violations($newPassword, $this->identifierSeeds((string) $user->id, $user));
        if ($violations !== []) {
            return $this->policyViolation($violations);
        }
        DB::transaction(function () use ($challenge, $user, $newPassword): void {
            $this->credentials->createForUser((string) $user->id, Hash::make($newPassword));
            $this->users->setStatus((string) $user->id, User::STATUS_ACTIVE, null);
            $this->challenges->consume((string) $challenge->id);
            $this->audit->audit('identity.bootstrap.account_activated', (string) $user->id, 'user', 'user', (string) $user->id, 'success');
            $this->audit->security('auth.account.activated', (string) $user->id, null, 'success', ['via' => 'bootstrap_setup_token']);
        });

        return (new HttpSuccessResponseObject)->setStatus(201)->setMessage((string) trans('identity.credentials.account_setup_completed'));
    }

    public function startEmailVerification(User $user): HttpSuccessResponseObject
    {
        $targets = [];
        foreach ($this->identifiers->identifiersForUser((string) $user->id) as $identifier) {
            if ((string) $identifier->kind === LoginIdentifier::KIND_EMAIL && $identifier->verified_at === null) {
                $targets[] = $identifier;
            }
        }
        foreach ($targets as $identifier) {
            $token = $this->generateChallengeToken();
            $this->identifiers->createVerification(
                (string) $identifier->id,
                hash('sha256', $token),
                now()->addMinutes(max(5, (int) config('identity.challenge.email_verification_ttl_minutes', 1440))),
            );
            $this->delivery->deliver(new DeliveryMessage(
                purpose: 'email_verification',
                toIdentifier: (string) $identifier->identifier,
                toIdentifierKind: (string) $identifier->kind,
                token: $token,
                locale: (string) app()->getLocale(),
            ));
        }
        $this->audit->security('auth.email_verification.requested', (string) $user->id, null, 'success', ['targets' => count($targets)]);

        return (new HttpSuccessResponseObject)->setStatus(200)->setMessage((string) trans('identity.credentials.verification_requested'));
    }

    public function confirmEmailVerification(string $token): ResponseObject
    {
        $verification = $this->identifiers->findUsableVerification(hash('sha256', $token));
        if ($verification === null) {
            return $this->challengeInvalid();
        }
        DB::transaction(function () use ($verification): void {
            $this->identifiers->consumeVerification((string) $verification->id);
            $this->identifiers->markVerified((string) $verification->login_identifier_id);
            $this->audit->audit('identity.credential.email_verified', null, 'system', 'login_identifier', (string) $verification->login_identifier_id, 'success');
            $this->audit->security('auth.email_verification.completed', null, null, 'success', ['login_identifier_id' => (string) $verification->login_identifier_id]);
        });

        return (new HttpSuccessResponseObject)->setStatus(200)->setMessage((string) trans('identity.credentials.verification_completed'));
    }

    /** @param list<string> $seeds */
    private function identifierSeeds(string $userId, User $user): array
    {
        $seeds = [];
        foreach ($this->identifiers->identifiersForUser($userId) as $identifier) {
            $seeds[] = (string) $identifier->normalized;
            if ((string) $identifier->kind === LoginIdentifier::KIND_EMAIL) {
                $seeds[] = (string) Str::before($identifier->normalized, '@');
            }
        }
        $displayName = $user->profile?->display_name;
        if (is_string($displayName) && $displayName !== '') {
            $seeds[] = $displayName;
        }

        return $seeds;
    }

    private function generateChallengeToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** @param list<string> $violations */
    private function policyViolation(array $violations): HttpErrorResponseObjectOfResult
    {
        return (new HttpErrorResponseObjectOfResult)->setStatus(400)->setCode(400)
            ->setMessage(implode(' ', array_map(fn (string $key) => (string) trans($key), $violations)));
    }

    private function challengeInvalid(): HttpErrorResponseObjectOfResult
    {
        return (new HttpErrorResponseObjectOfResult)->setStatus(400)->setCode(400)->setMessage((string) trans('identity.credentials.challenge_invalid'));
    }
}
