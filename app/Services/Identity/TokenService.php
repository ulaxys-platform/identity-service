<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Services\Identity;

use Illuminate\Support\Str;
use Ulaxys\Identity\Dto\Identity\TokenGrantDto;
use Ulaxys\Identity\Models\AuthSession;
use Ulaxys\Identity\Models\User;
use Ulaxys\Identity\Repositories\RepositoryInterface\AuthSessionRepositoryInterface;
use Ulaxys\Identity\Repositories\RepositoryInterface\RefreshTokenRepositoryInterface;
use Ulaxys\Identity\Repositories\RepositoryInterface\UserRepositoryInterface;
use Ulaxys\Identity\Services\Identity\Tokens\JwtCodec;
use Ulaxys\Identity\Services\Identity\Tokens\SessionGrant;
use Ulaxys\Identity\Services\Identity\Tokens\SigningKeyService;
use Ulaxys\Identity\Services\Identity\Tokens\TokenException;

/**
 * Issues and rotates I1 session tokens (ADR-0002). Access tokens carry no
 * authorization data; refresh secrets are stored only as SHA-256 hashes and
 * every presented token is single-use within a rotation family.
 */
final class TokenService
{
    public function __construct(
        private readonly JwtCodec $codec,
        private readonly SigningKeyService $signingKeys,
        private readonly UserRepositoryInterface $users,
        private readonly AuthSessionRepositoryInterface $sessions,
        private readonly RefreshTokenRepositoryInterface $refreshTokens,
    ) {}

    public function issueForSession(User $user, AuthSession $session): SessionGrant
    {
        $signingKey = $this->signingKeys->signingKey();
        $accessTtl = max(60, (int) config('identity.token.access_ttl_seconds', 600));
        $refreshMinutes = max(5, (int) config('identity.token.refresh_ttl_minutes', 720));
        $accessToken = $this->codec->encode($this->accessClaims($user, $session, $accessTtl), $signingKey['private_key_pem'], $signingKey['kid']);

        return $this->grant($accessToken, $accessTtl, $user, $session, (string) Str::ulid(), $refreshMinutes);
    }

    public function rotate(string $presentedRefreshToken): SessionGrant
    {
        $tokenHash = hash('sha256', $presentedRefreshToken);
        $record = $this->refreshTokens->findByTokenHash($tokenHash);
        if ($record === null) {
            throw new TokenException(TokenException::INVALID_REFRESH, 'Refresh token is not recognized.');
        }
        if (! $record->isUsable()) {
            // Replay of a consumed or revoked token: revoke the whole family
            // and the owning session, then fail with a generic response.
            $this->refreshTokens->revokeFamily((string) $record->family_id, 'refresh_reuse_detected');
            $this->sessions->revoke((string) $record->session_id, 'refresh_reuse_detected');
            throw new TokenException(TokenException::REUSE_DETECTED, 'Refresh token replay detected.');
        }
        $user = $this->users->findById((string) $record->user_id);
        $session = $this->sessions->findById((string) $record->session_id);
        if ($user === null || $session === null || ! $user->isLoginEligible() || ! $session->isUsable()) {
            $this->refreshTokens->revokeFamily((string) $record->family_id, 'session_no_longer_usable');
            throw new TokenException(TokenException::INVALID_REFRESH, 'Refresh token is no longer usable.');
        }
        $this->refreshTokens->consume((string) $record->id);
        $signingKey = $this->signingKeys->signingKey();
        $accessTtl = max(60, (int) config('identity.token.access_ttl_seconds', 600));
        $refreshMinutes = max(5, (int) config('identity.token.refresh_ttl_minutes', 720));
        $accessToken = $this->codec->encode($this->accessClaims($user, $session, $accessTtl), $signingKey['private_key_pem'], $signingKey['kid']);

        return $this->grant($accessToken, $accessTtl, $user, $session, (string) $record->family_id, $refreshMinutes);
    }

    /** @return array<string, mixed> */
    private function accessClaims(User $user, AuthSession $session, int $accessTtl): array
    {
        $now = time();

        return [
            'iss' => (string) config('identity.issuer'),
            'aud' => (string) config('identity.token.audience'),
            'sub' => (string) $user->id,
            'sid' => (string) $session->id,
            'amr' => $session->authentication_methods ?? ['password'],
            'auth_time' => $session->created_at?->getTimestamp() ?? $now,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $accessTtl,
            'jti' => bin2hex(random_bytes(16)),
        ];
    }

    private function grant(string $accessToken, int $accessTtl, User $user, AuthSession $session, string $familyId, int $refreshMinutes): SessionGrant
    {
        $secret = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $this->refreshTokens->create([
            'session_id' => (string) $session->id,
            'user_id' => (string) $user->id,
            'family_id' => $familyId,
            'token_hash' => hash('sha256', $secret),
            'expires_at' => now()->addMinutes($refreshMinutes),
        ]);

        return new SessionGrant(
            new TokenGrantDto(
                accessToken: $accessToken,
                tokenType: 'Bearer',
                expiresIn: $accessTtl,
                refreshToken: $secret,
                refreshTokenExpiresIn: $refreshMinutes * 60,
            ),
            $user,
            $session,
        );
    }
}
