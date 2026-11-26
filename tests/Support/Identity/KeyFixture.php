<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Tests\Support\Identity;

use RuntimeException;
use Ulaxys\Identity\Services\Identity\Tokens\Jwk;

/** Generates a disposable RSA keypair for tests; never used in production. */
final class KeyFixture
{
    /** @return array{private: string, public: string, kid: string} */
    public static function rsaKeyPair(): array
    {
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($resource === false) {
            throw new RuntimeException('openssl_pkey_new failed; check openssl extension availability.');
        }
        openssl_pkey_export($resource, $private);
        $details = openssl_pkey_get_details($resource);
        if (! is_array($details) || ! isset($details['key'])) {
            throw new RuntimeException('openssl key details unavailable.');
        }
        $public = (string) $details['key'];

        return ['private' => $private, 'public' => $public, 'kid' => Jwk::thumbprint($public)];
    }
}
