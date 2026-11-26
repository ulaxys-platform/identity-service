<?php

namespace Ulaxys\Identity\Integration;

/** Narrowly scoped native Identity payload; never an arbitrary output extension. */
final readonly class NativeIdentityFile
{
    private function __construct(public string $path, public string $contents) {}

    public static function fromRenderer(string $path, string $contents): self
    {
        if (! preg_match('~^identity/(?:README\.md|database/seeders/(?:Permissions/[A-Za-z][A-Za-z0-9_]*PermissionSeeder\.php|Support/(?:PermissionSeedWriter\.php|permissions-[A-Za-z][A-Za-z0-9_-]*(?:\.php|-keys\.json))))$~D', $path)) {
            throw new IntegrationException('Unapproved native Identity output path.');
        }
        try {
            if (str_ends_with($path, '.php')) {
                token_get_all($contents, TOKEN_PARSE);
            } elseif (str_ends_with($path, '.json')) {
                json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            }
        } catch (\ParseError|\JsonException $error) {
            throw new IntegrationException('Invalid generated Identity file: '.$path, 0, $error);
        }

        return new self($path, $contents);
    }
}
