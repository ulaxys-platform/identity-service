<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Services\Identity\Tokens;

use RuntimeException;

/** RFC 7517 JWK rendering and RFC 7638 thumbprint for RSA public keys. */
final class Jwk
{
    /** @return array<string, string> */
    public static function fromPublicKeyPem(string $publicKeyPem, string $algorithm = 'RS256'): array
    {
        [$n, $e] = self::components($publicKeyPem);
        $nEncoded = self::toBase64Url($n);
        $eEncoded = self::toBase64Url($e);

        return [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => $algorithm,
            'kid' => self::thumbprintFromComponents($nEncoded, $eEncoded),
            'n' => $nEncoded,
            'e' => $eEncoded,
        ];
    }

    public static function thumbprint(string $publicKeyPem): string
    {
        [$n, $e] = self::components($publicKeyPem);

        return self::thumbprintFromComponents(self::toBase64Url($n), self::toBase64Url($e));
    }

    /** @return array{0: string, 1: string} raw modulus and exponent bytes */
    private static function components(string $publicKeyPem): array
    {
        $details = openssl_pkey_get_details(openssl_pkey_get_public($publicKeyPem));
        if ($details === false || ! isset($details['rsa']['n'], $details['rsa']['e'])) {
            throw new RuntimeException('Public key is not a readable RSA key.');
        }

        return [(string) $details['rsa']['n'], (string) $details['rsa']['e']];
    }

    private static function thumbprintFromComponents(string $n, string $e): string
    {
        // RFC 7638 canonical form: lexicographic member order, no whitespace.
        $canonical = '{"e":"'.$e.'","kty":"RSA","n":"'.$n.'"}';

        return self::toBase64Url(hash('sha256', $canonical, true));
    }

    private static function toBase64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
