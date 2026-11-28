<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Tests\Feature\Identity;

use Ulaxys\Identity\Services\Identity\Tokens\Jwk;
use Ulaxys\Identity\Services\Identity\Tokens\JwtCodec;
use Ulaxys\Identity\Services\Identity\Tokens\TokenException;
use Ulaxys\Identity\Tests\Support\Identity\KeyFixture;
use Ulaxys\Identity\Tests\TestCase;

final class JwtCodecTest extends TestCase
{
    private JwtCodec $codec;

    private array $keys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->codec = new JwtCodec('https://identity.test', 30);
        $this->keys = KeyFixture::rsaKeyPair();
    }

    private function claims(array $overrides = []): array
    {
        $now = time();

        return array_replace(['iss' => 'https://identity.test', 'aud' => 'ulaxys-platform', 'sub' => '01TESTSUBJECT0000000', 'sid' => '01TESTSESSION0000000',
            'amr' => ['password'], 'iat' => $now, 'nbf' => $now, 'exp' => $now + 600, 'jti' => 'test-jti'], $overrides);
    }

    public function test_roundtrips_claims_with_rs256_signature(): void
    {
        $token = $this->codec->encode($this->claims(), $this->keys['private'], $this->keys['kid']);
        $decoded = $this->codec->decode($token, [$this->keys['kid'] => $this->keys['public']], 'ulaxys-platform');
        $this->assertSame('01TESTSUBJECT0000000', $decoded['sub']);
        $this->assertSame('01TESTSESSION0000000', $decoded['sid']);
    }

    public function test_tampered_payload_fails_signature_verification(): void
    {
        $token = $this->codec->encode($this->claims(), $this->keys['private'], $this->keys['kid']);
        [$head, , $signature] = explode('.', $token);
        $forged = $head.'.'.base64_encode(json_encode($this->claims(['sub' => 'attacker']))).'.';
        $forged = $head.'.'.$this->base64Url(json_encode($this->claims(['sub' => 'attacker']))).'.'.$signature;
        try {
            $this->codec->decode($forged, [$this->keys['kid'] => $this->keys['public']], 'ulaxys-platform');
            $this->fail('Tampered payload must not validate.');
        } catch (TokenException $exception) {
            $this->assertSame(TokenException::INVALID_SIGNATURE, $exception->reason);
        }
    }

    public function test_none_algorithm_is_never_accepted(): void
    {
        $header = $this->base64Url(json_encode(['alg' => 'none', 'typ' => 'JWT', 'kid' => $this->keys['kid']]));
        $payload = $this->base64Url(json_encode($this->claims()));
        try {
            $this->codec->decode($header.'.'.$payload.'.', [$this->keys['kid'] => $this->keys['public']], 'ulaxys-platform');
            $this->fail('Unsigned JWTs must never validate.');
        } catch (TokenException $exception) {
            $this->assertSame(TokenException::UNSUPPORTED_ALGORITHM, $exception->reason);
        }
    }

    public function test_hs256_is_rejected_by_the_algorithm_allowlist(): void
    {
        $header = $this->base64Url(json_encode(['alg' => 'HS256', 'typ' => 'JWT', 'kid' => $this->keys['kid']]));
        $payload = $this->base64Url(json_encode($this->claims()));
        try {
            $this->codec->decode($header.'.'.$payload.'.fakesig', [$this->keys['kid'] => $this->keys['public']], 'ulaxys-platform');
            $this->fail('Symmetric algorithms must be rejected.');
        } catch (TokenException $exception) {
            $this->assertSame(TokenException::UNSUPPORTED_ALGORITHM, $exception->reason);
        }
    }

    public function test_unknown_kid_is_rejected(): void
    {
        $token = $this->codec->encode($this->claims(), $this->keys['private'], $this->keys['kid']);
        try {
            $this->codec->decode($token, ['other-kid' => $this->keys['public']], 'ulaxys-platform');
            $this->fail('Unknown kid must be rejected.');
        } catch (TokenException $exception) {
            $this->assertSame(TokenException::UNKNOWN_KID, $exception->reason);
        }
    }

    public function test_expired_tokens_are_rejected_but_clock_skew_is_bounded(): void
    {
        $expired = $this->codec->encode($this->claims(['exp' => time() - 3600]), $this->keys['private'], $this->keys['kid']);
        try {
            $this->codec->decode($expired, [$this->keys['kid'] => $this->keys['public']], 'ulaxys-platform');
            $this->fail('Expired tokens must be rejected.');
        } catch (TokenException $exception) {
            $this->assertSame(TokenException::EXPIRED, $exception->reason);
        }
        $withinSkew = $this->codec->encode($this->claims(['exp' => time() - 10]), $this->keys['private'], $this->keys['kid']);
        $this->assertSame('01TESTSUBJECT0000000', $this->codec->decode($withinSkew, [$this->keys['kid'] => $this->keys['public']], 'ulaxys-platform')['sub']);
    }

    public function test_not_before_future_tokens_are_rejected(): void
    {
        $future = $this->codec->encode($this->claims(['nbf' => time() + 3600]), $this->keys['private'], $this->keys['kid']);
        try {
            $this->codec->decode($future, [$this->keys['kid'] => $this->keys['public']], 'ulaxys-platform');
            $this->fail('Future-dated tokens must be rejected.');
        } catch (TokenException $exception) {
            $this->assertSame(TokenException::NOT_YET_VALID, $exception->reason);
        }
    }

    public function test_issuer_and_audience_mismatches_are_rejected(): void
    {
        $token = $this->codec->encode($this->claims(['iss' => 'https://attacker.test']), $this->keys['private'], $this->keys['kid']);
        try {
            $this->codec->decode($token, [$this->keys['kid'] => $this->keys['public']], 'ulaxys-platform');
            $this->fail('Issuer mismatch must be rejected.');
        } catch (TokenException $exception) {
            $this->assertSame(TokenException::ISSUER_MISMATCH, $exception->reason);
        }
        $wrongAudience = $this->codec->encode($this->claims(['aud' => 'other-platform']), $this->keys['private'], $this->keys['kid']);
        try {
            $this->codec->decode($wrongAudience, [$this->keys['kid'] => $this->keys['public']], 'ulaxys-platform');
            $this->fail('Audience mismatch must be rejected.');
        } catch (TokenException $exception) {
            $this->assertSame(TokenException::AUDIENCE_MISMATCH, $exception->reason);
        }
    }

    public function test_signatures_from_other_keys_are_rejected(): void
    {
        $otherKeys = KeyFixture::rsaKeyPair();
        $token = $this->codec->encode($this->claims(), $otherKeys['private'], $this->keys['kid']);
        try {
            $this->codec->decode($token, [$this->keys['kid'] => $this->keys['public']], 'ulaxys-platform');
            $this->fail('Cross-key signatures must be rejected.');
        } catch (TokenException $exception) {
            $this->assertSame(TokenException::INVALID_SIGNATURE, $exception->reason);
        }
    }

    public function test_jwk_thumbprint_matches_the_fixture_kid(): void
    {
        $jwk = Jwk::fromPublicKeyPem($this->keys['public']);
        $this->assertSame($this->keys['kid'], $jwk['kid']);
        $this->assertSame('RSA', $jwk['kty']);
        $this->assertSame('sig', $jwk['use']);
        $this->assertSame('RS256', $jwk['alg']);
        $this->assertArrayNotHasKey('d', $jwk, 'Private material must never appear in a JWK.');
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
