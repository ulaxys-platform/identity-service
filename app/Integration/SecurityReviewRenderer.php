<?php

namespace Ulaxys\Identity\Integration;

/** No executable or legacy-loadable artifacts until downstream support is verified. */
final class SecurityReviewRenderer
{
    public function generate(array $snapshot, array $targets): array
    {
        $files = [];
        foreach ($targets as $target) {
            [$path, $kind] = match ($target) {
                'gateway' => ['gateway/security-review.json', 'gateway-security-review'],
                'deploy' => ['deploy/security-review.json', 'deploy-security-review'],
                'permissions' => ['identity/security-review.json', 'identity-security-review'],
                default => throw new IntegrationException('Unknown security review target.'),
            };
            $files[$path] = [
                'schemaVersion' => 2,
                'kind' => $kind,
                'service' => $snapshot['service'],
                'deployReady' => false,
                'runtimeVerified' => false,
                'executable' => false,
                'reason' => 'Downstream security-contract support requires independent verification; not a runtime import.',
                'operations' => $snapshot['routes'],
                'permissions' => $snapshot['permissions'],
                'excludedInfrastructure' => $snapshot['excluded'],
                'warnings' => $snapshot['warnings'],
            ];
        }

        return $files;
    }
}
