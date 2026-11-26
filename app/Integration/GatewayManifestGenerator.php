<?php

namespace Ulaxys\Identity\Integration;

final class GatewayManifestGenerator
{
    public function generate(array $snapshot, array $config): array
    {
        $files = [];
        foreach ($config['gateway'] as $target => $settings) {
            $routes = $excluded = [];
            foreach ($snapshot['routes'] as $route) {
                if (! $route['expose']) {
                    $excluded[] = ['name' => $route['name'], 'methods' => $route['methods'], 'uri' => $route['uri'], 'reason' => $route['exemption'] ?? 'Not exposed by explicit metadata.'];

                    continue;
                }
                $routes[] = [...$route, 'destinationPath' => '/'.$settings['prefix'].($route['uri'] === '/' ? '' : $route['uri'])];
            }
            $files[$settings['file']] = [
                'schemaVersion' => 1, 'kind' => 'gateway-route-manifest', 'target' => $target,
                'service' => $snapshot['service'], 'adapter' => 'intermediate', 'deployReady' => false,
                'securityRequirements' => ['authenticateAtTrustedGateway' => true, 'stripIncomingSignature' => true,
                    'neverTrustIncomingIdentityHeaders' => true, 'injectSignatureFromGatewayRuntimeConfig' => true,
                    'enforceExplicitPermissionMapping' => true],
                'routes' => $routes, 'excludedOperations' => $excluded, 'excludedInfrastructure' => $snapshot['excluded'],
            ];
        }
        $files['gateway/compatibility.json'] = [
            'schemaVersion' => 1, 'kind' => 'gateway-compatibility-report', 'service' => $snapshot['service'],
            'nativeAdaptersVerified' => false, 'deployReady' => false, 'format' => 'intermediate JSON declarations',
            'requiredVerification' => ['Install the native MainGate package manually when selected, and verify against the real MainGate runtime.',
                'Confirm configured ServiceProxy destination, internal Signature injection and trusted identity-header policy.',
                'Native Service Gateway adapter and native Identity seeder are excluded; their declarations remain intermediate.'],
            'warnings' => $snapshot['warnings'],
        ];

        return $files;
    }
}
