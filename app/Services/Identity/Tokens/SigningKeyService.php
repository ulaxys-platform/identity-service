<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Services\Identity\Tokens;

use Illuminate\Support\Facades\File;
use RuntimeException;
use Ulaxys\Identity\Repositories\RepositoryInterface\SigningKeyRepositoryInterface;

/**
 * Owns signing key material lifecycle. Private PEMs live on secure storage
 * (mounted from a secret manager in production); the database holds metadata
 * only. Generation is an explicit operator command, never an HTTP action.
 */
final class SigningKeyService
{
    public function __construct(private readonly SigningKeyRepositoryInterface $repository) {}

    /** @return array{kid: string, private_key_pem: string, public_key_pem: string} */
    public function signingKey(): array
    {
        $key = $this->repository->active();
        if ($key === null) {
            throw new TokenException(TokenException::KEYS_UNAVAILABLE, 'No active signing key is configured.');
        }

        return [
            'kid' => (string) $key->kid,
            'private_key_pem' => $this->readFile((string) $key->private_key_path),
            'public_key_pem' => $this->readFile((string) $key->public_key_path),
        ];
    }

    /** @return array<string, string> kid => public PEM */
    public function verificationKeys(): array
    {
        $keys = $this->repository->verifiable();
        if ($keys === []) {
            throw new TokenException(TokenException::KEYS_UNAVAILABLE, 'No verification keys are published.');
        }
        $map = [];
        foreach ($keys as $key) {
            $map[(string) $key->kid] = $this->readFile((string) $key->public_key_path);
        }

        return $map;
    }

    /** @return array{kid: string, private_path: string, public_path: string} */
    public function generate(bool $rotate): array
    {
        if (! $rotate && $this->repository->active() !== null) {
            throw new RuntimeException('An active signing key already exists; use --rotate to publish a new key with overlap.');
        }
        $storagePath = (string) config('identity.keys.storage_path');
        File::ensureDirectoryExists($storagePath, 0700);
        $config = [
            'private_key_bits' => max(2048, (int) config('identity.keys.key_size', 3072)),
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];
        $keyResource = openssl_pkey_new($config);
        if ($keyResource === false) {
            throw new RuntimeException('openssl_pkey_new failed; ensure the openssl extension and OPENSSL_CONF are available.');
        }
        openssl_pkey_export($keyResource, $privatePem, null, $config);
        $details = openssl_pkey_get_details($keyResource);
        if (! is_array($details) || ! isset($details['key'])) {
            throw new RuntimeException('Generated key details are unreadable.');
        }
        $publicPem = (string) $details['key'];
        $kid = Jwk::thumbprint($publicPem);
        $privatePath = $storagePath.'/identity-'.$kid.'.private.pem';
        $publicPath = $storagePath.'/identity-'.$kid.'.public.pem';
        File::put($privatePath, $privatePem);
        File::put($publicPath, $publicPem);
        chmod($privatePath, 0600);
        chmod($publicPath, 0644);
        $this->repository->create([
            'kid' => $kid,
            'algorithm' => 'RS256',
            'private_key_path' => $privatePath,
            'public_key_path' => $publicPath,
            'is_active' => true,
            'activated_at' => now(),
        ]);
        $this->repository->retireOthers($kid);

        return ['kid' => $kid, 'private_path' => $privatePath, 'public_path' => $publicPath];
    }

    private function readFile(string $path): string
    {
        if (! is_file($path)) {
            throw new TokenException(TokenException::KEYS_UNAVAILABLE, 'Signing key file is missing on this host.');
        }
        $content = file_get_contents($path);
        if ($content === false || $content === '') {
            throw new TokenException(TokenException::KEYS_UNAVAILABLE, 'Signing key file is unreadable.');
        }

        return $content;
    }
}
