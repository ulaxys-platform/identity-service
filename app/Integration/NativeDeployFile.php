<?php

namespace Ulaxys\Identity\Integration;

/** Fixed, review-only ulaxys-deploy files. No arbitrary native output paths. */
final readonly class NativeDeployFile
{
    private function __construct(public string $path, public string $contents) {}

    public static function fromRenderer(string $path, string $contents): self
    {
        if (! in_array($path, [
            'deploy/README.md',
            'deploy/compose/local.service.yaml',
            'deploy/compose/production.service.yaml',
            'deploy/environments/runtime.env.example',
            'deploy/environments/secrets.required.json',
            'deploy/environments/images.env.example',
        ], true)) {
            throw new IntegrationException('Unapproved deploy output path.');
        }
        if (str_ends_with($path, '.yaml') || str_ends_with($path, '.json')) {
            try {
                $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($decoded)) {
                    throw new IntegrationException('Invalid deploy JSON-compatible Compose/document.');
                }
            } catch (\JsonException $error) {
                throw new IntegrationException('Invalid generated deploy file: '.$path, 0, $error);
            }
        }

        return new self($path, $contents);
    }
}
