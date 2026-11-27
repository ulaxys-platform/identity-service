<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Services\Identity\Tokens;

use Throwable;

/**
 * Minimal RS256-only JWT codec built on PHP core openssl (ADR-0001).
 *
 * Design boundaries:
 *  - Algorithm allowlist: only RS256. `none`, HS256 and every other alg value
 *    are rejected before any key material is touched.
 *  - Signature, issuer, audience, expiry and not-before are always validated
 *    with a bounded clock skew.
 *  - This class implements no grant logic; protocol grants stay deferred to
 *    the I2 engine decision.
 */
final class JwtCodec
{
    private const ALGORITHM = 'RS256';

    private const OPENSSL_ALGORITHM = OPENSSL_ALGO_SHA256;

    public function __construct(
        private readonly string $issuer,
        private readonly int $clockSkewSeconds = 30,
    ) {}

    /** @param array<string, mixed> $claims */
    public function encode(array $claims, string $privateKeyPem, string $kid): string
    {
        $key = openssl_pkey_get_private($privateKeyPem);
        if ($key === false) {
            throw new TokenException(TokenException::KEYS_UNAVAILABLE, 'Signing key material is unreadable.');
        }
        $segments = [
            $this->encodeSegment(['alg' => self::ALGORITHM, 'typ' => 'JWT', 'kid' => $kid]),
            $this->encodeSegment($claims),
        ];
        $input = implode('.', $segments);
        $signature = '';
        if (openssl_sign($input, $signature, $key, self::OPENSSL_ALGORITHM) !== true) {
            throw new TokenException(TokenException::KEYS_UNAVAILABLE, 'JWT signing failed.');
        }
        $segments[] = $this->toBase64Url($signature);

        return implode('.', $segments);
    }

    /**
     * @param  array<string, string>  $publicKeysByKid  kid => PEM
     * @return array<string, mixed> validated claims
     */
    public function decode(string $token, array $publicKeysByKid, ?string $expectedAudience = null): array
    {
        $segments = explode('.', $token);
        if (count($segments) !== 3) {
            throw new TokenException(TokenException::MALFORMED, 'JWT must contain exactly three segments.');
        }
        [$head, $payload, $signatureSegment] = $segments;
        $header = $this->decodeSegment($head);
        if (! is_array($header) || ($header['alg'] ?? null) !== self::ALGORITHM) {
            throw new TokenException(TokenException::UNSUPPORTED_ALGORITHM, 'JWT algorithm is not allowed.');
        }
        $kid = $header['kid'] ?? null;
        if (! is_string($kid) || $kid === '' || ! array_key_exists($kid, $publicKeysByKid)) {
            throw new TokenException(TokenException::UNKNOWN_KID, 'JWT signing key is unknown or no longer published.');
        }
        $signature = $this->fromBase64Url($signatureSegment);
        $publicKey = openssl_pkey_get_public((string) $publicKeysByKid[$kid]);
        if ($signature === false || $publicKey === false) {
            throw new TokenException(TokenException::INVALID_SIGNATURE, 'JWT signature could not be processed.');
        }
        $verified = openssl_verify($head.'.'.$payload, $signature, $publicKey, self::OPENSSL_ALGORITHM);
        if ($verified !== 1) {
            throw new TokenException(TokenException::INVALID_SIGNATURE, 'JWT signature verification failed.');
        }
        $claims = $this->decodeSegment($payload);
        if (! is_array($claims)) {
            throw new TokenException(TokenException::MALFORMED, 'JWT payload must be a JSON object.');
        }
        $now = time();
        $skew = max(0, $this->clockSkewSeconds);
        foreach (['exp', 'iat', 'nbf'] as $timeClaim) {
            if (isset($claims[$timeClaim]) && ! is_int($claims[$timeClaim])) {
                throw new TokenException(TokenException::INVALID_CLAIMS, 'JWT '.$timeClaim.' must be an integer timestamp.');
            }
        }
        if (! isset($claims['exp']) || $claims['exp'] < $now - $skew) {
            throw new TokenException(TokenException::EXPIRED, 'JWT has expired.');
        }
        if (isset($claims['nbf']) && $claims['nbf'] > $now + $skew) {
            throw new TokenException(TokenException::NOT_YET_VALID, 'JWT is not valid yet.');
        }
        if (($claims['iss'] ?? null) !== $this->issuer) {
            throw new TokenException(TokenException::ISSUER_MISMATCH, 'JWT issuer mismatch.');
        }
        if ($expectedAudience !== null && ! $this->audienceMatches($claims['aud'] ?? null, $expectedAudience)) {
            throw new TokenException(TokenException::AUDIENCE_MISMATCH, 'JWT audience mismatch.');
        }

        return $claims;
    }

    private function audienceMatches(mixed $audience, string $expected): bool
    {
        if (is_string($audience)) {
            return hash_equals($audience, $expected);
        }
        if (is_array($audience)) {
            foreach ($audience as $value) {
                if (is_string($value) && hash_equals($value, $expected)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<string, mixed> $data */
    private function encodeSegment(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return $this->toBase64Url($json);
    }

    private function decodeSegment(string $segment): mixed
    {
        $raw = $this->fromBase64Url($segment);
        if ($raw === false) {
            throw new TokenException(TokenException::MALFORMED, 'JWT segment is not valid base64url.');
        }
        try {
            return json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new TokenException(TokenException::MALFORMED, 'JWT segment is not valid JSON.');
        }
    }

    private function toBase64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /** @return string|false */
    private function fromBase64Url(string $value)
    {
        return base64_decode(strtr($value, '-_', '+/'), true);
    }
}
