<?php

namespace Ulaxys\Identity\Integration;

/** Deterministic review-only package, never an installer. */
final class DeployPackageRenderer
{
    public function generate(array $snapshot, array $config): array
    {
        $service = IntegrationMetadata::identifier($config['service']);
        if (! preg_match('/^[a-z][a-z0-9-]*$/D', $service)) {
            throw new IntegrationException('Deploy requires a specific lowercase service identity.');
        }
        $templateOnly = $service === 'service';
        $name = $service.'-service';
        $prefix = strtoupper(str_replace('-', '_', $name));
        $var = static fn (string $key): string => '$'.'{'.$key.'}';
        $image = '$'.'{'.$prefix.'_IMAGE:-ghcr.io/ulaxys-platform/'.$name.':'.$var($prefix.'_VERSION').'}';
        $logging = ['driver' => 'json-file', 'options' => ['max-size' => '10m', 'max-file' => '3']];
        $local = ['services' => [$name => [
            'image' => $image, 'restart' => 'unless-stopped',
            'env_file' => [$var('ULAXYS_ENV_DIR').'/services/'.$name.'.env', $var('ULAXYS_SECRET_DIR').'/'.$name.'.env'],
            'ports' => [$var($prefix.'_BIND_IP').':'.$var($prefix.'_PORT').':8080'],
            'networks' => ['platform'], 'logging' => $logging,
            'depends_on' => ['postgres' => ['condition' => 'service_healthy']],
        ]]];
        $production = ['services' => [$name => [
            'image' => $image, 'restart' => 'unless-stopped',
            'ports' => [$var('ULAXYS_BIND_IP').':'.$var('ULAXYS_PORT').':8080'],
            'logging' => $logging,
            'env_file' => [
                ['path' => $var('ULAXYS_PRODUCTION_ENV_DIR').'/services/'.$name.'.env', 'format' => 'raw'],
                ['path' => $var('ULAXYS_PRODUCTION_SECRET_DIR').'/'.$name.'.env', 'format' => 'raw'],
            ],
        ]]];
        $catalog = [
            'name' => $name, 'category' => 'services', 'profiles' => ['local', 'production'],
            'composeService' => $name, 'exposure' => ['local' => 'loopback', 'production' => 'internal'],
            'dependencies' => ['postgres'],
            'operations' => ['shell' => true, 'artisan' => true, 'swagger' => true],
            'legacyDirectory' => $name,
            'smoke' => ['binding' => $prefix, 'path' => '/healthCheck/apiStatus',
                'evidence' => 'Template route; no published image or deployed runtime verification', 'status' => 'documentation-only'],
            'openapi' => ['path' => '/openapi.json', 'profiles' => ['local'], 'evidence' => 'Template route'],
        ];
        $contract = [
            'runtime' => ['APP_ENV', 'APP_DEBUG', 'APP_NAME', 'LOG_CHANNEL',
                'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME'],
            'secrets' => ['APP_KEY', 'DB_PASSWORD', 'SERVICE_SIGNATURE'],
            'database' => ['format' => 'laravel', 'required' => true],
            'requiredWhen' => [
                ['key' => 'CACHE_DRIVER', 'equals' => 'redis', 'runtime' => ['REDIS_HOST', 'REDIS_PORT']],
                ['key' => 'QUEUE_CONNECTION', 'equals' => 'redis', 'runtime' => ['REDIS_HOST', 'REDIS_PORT']],
                ['key' => 'SESSION_DRIVER', 'equals' => 'redis', 'runtime' => ['REDIS_HOST', 'REDIS_PORT']],
            ],
        ];
        $json = static fn (array $data): string => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        $readme = '# '.$name." — uLaxys Deploy integration package\n\n"
            ."Target: ulaxys-platform/ulaxys-deploy at reviewed commit 4d5f3996bc4ea545277665b24c534b28fdcf58b8. Review and **manually transfer** only. Never overwrite existing catalogs.\n\n"
            ."1. Merge patches/service-catalog.json into service.json; merge the service entry from compose/local.service.yaml into compose/services.local.yaml.\n"
            .'2. Review and copy compose/production.service.yaml to compose/production/services/'.$name.".yaml. Select a real production target in private targets.json and verify image digest before use.\n"
            .'3. Merge patches/deployment-contract.json into config/deployment.contract.json. Review runtime.env.example for environments/example/services/'.$name.".env and add reviewed bind/port and image env entries.\n"
            ."4. Provision PostgreSQL inventory/roles/ACL and private runtime/secrets through the existing deploy operator; no passwords, keys, databases or ports are provided here.\n"
            ."5. Review patches/operator-capabilities.json, NOT an executable registry entry. Operator V2 uses strict reviewed allowlists; add supported capabilities only through an independently reviewed deploy change.\n"
            ."6. Choose explicit production placement, private bind/port, persistent storage and external dependencies. A production-capable catalog entry also requires manually updating ALL three reviewed config/topologies/ examples and private environments/production/targets.json. patches/production-topology.json deliberately leaves values null.\n"
            ."7. Validate the **combined** deploy checkout using python3 scripts/validate_repository.py, python3 -m unittest discover -s tests, and the existing invoke local validate command. Passing these does not establish real runtime readiness.\n"
            ."8. Separately install MainGate and Identity packages manually; verify a real gateway → service → DB request, secrets and permission enforcement before rollout.\n\n"
            ."No files are installed into ulaxys-deploy or other repositories. No credentials, image tags, production hosts or ports are invented. JSON is valid YAML for Compose.\n";
        $runtime = implode("\n", [
            '# Review before installation; placeholder values must be replaced.',
            'APP_ENV=production', 'APP_DEBUG=false', 'APP_NAME='.$name,
            'APP_URL=http://localhost:REPLACE_ME', 'APP_TIMEZONE=UTC',
            'LOG_CHANNEL=stderr', 'DB_CONNECTION=pgsql', 'DB_HOST=postgres', 'DB_PORT=5432',
            'DB_DATABASE=REPLACE_ME', 'DB_USERNAME=REPLACE_ME',
            'DB_SSLMODE=prefer', 'CACHE_DRIVER=file', 'QUEUE_CONNECTION=sync',
            'SESSION_DRIVER=array', 'REDIS_PREFIX='.str_replace('-', '_', $service).'_', '',
        ]);
        $files = [
            'deploy/README.md' => NativeDeployFile::fromRenderer('deploy/README.md', $readme),
            'deploy/compose/local.service.yaml' => NativeDeployFile::fromRenderer('deploy/compose/local.service.yaml', $json($local)),
            'deploy/compose/production.service.yaml' => NativeDeployFile::fromRenderer('deploy/compose/production.service.yaml', $json($production)),
            'deploy/environments/runtime.env.example' => NativeDeployFile::fromRenderer('deploy/environments/runtime.env.example', $runtime),
            'deploy/environments/secrets.required.json' => NativeDeployFile::fromRenderer('deploy/environments/secrets.required.json', $json([
                'required' => $contract['secrets'], 'valuesIncluded' => false])),
            'deploy/environments/images.env.example' => NativeDeployFile::fromRenderer('deploy/environments/images.env.example',
                '# Verify a published image; review immutable digests.'."\n".$prefix.'_VERSION=REPLACE_ME'."\n"),
            'deploy/patches/service-catalog.json' => $this->fragment('service-catalog', 'service.json', $name, ['services' => [$catalog]]),
            'deploy/patches/deployment-contract.json' => $this->fragment('deployment-contract', 'config/deployment.contract.json', $name, ['local' => [$name => $contract]]),
            'deploy/patches/deployment-env.json' => $this->fragment('deployment-env', 'environments/example/deployment.env', $name, [
                'entries' => [$prefix.'_BIND_IP' => '127.0.0.1', $prefix.'_PORT' => 'REVIEW_PORT']]),
            'deploy/patches/images-env.json' => $this->fragment('images-env', 'environments/example/images.env', $name, [
                'entries' => [$prefix.'_VERSION' => 'REPLACE_ME']]),
            'deploy/patches/operator-capabilities.json' => $this->fragment('operator-capabilities', 'config/operator.capabilities.json', $name, [
                'reviewRequired' => true, 'suggestedOperations' => ['status', 'inspect', 'health', 'up', 'stop', 'restart', 'recreate', 'migrate'],
                'installableRegistryEntries' => [], 'reason' => 'Operator V2 capabilities require source-reviewed adapters and allowlist changes.']),
            'deploy/patches/production-topology.json' => $this->fragment('production-topology', 'config/topologies/', $name, [
                'reviewRequired' => true, 'exampleFiles' => ['single-host.json', 'existing-multi-host.json', 'isolated-services.json'],
                'privateTargetsPath' => 'environments/production/targets.json',
                'target' => null, 'bind' => null, 'port' => null, 'volume' => null]),
            'deploy/compatibility.json' => [
                'schemaVersion' => 1, 'kind' => 'deploy-compatibility',
                'service' => $name, 'targetRepository' => 'ulaxys-platform/ulaxys-deploy',
                'reviewedCommit' => '4d5f3996bc4ea545277665b24c534b28fdcf58b8',
                'automaticallyInstalled' => false, 'runtimeVerified' => false, 'productionReady' => false,
                'templatePlaceholder' => $templateOnly,
            ],
        ];

        return $files;
    }

    private function fragment(string $kind, string $path, string $service, array $patch): array
    {
        return [
            'schemaVersion' => 1, 'kind' => 'deploy-'.$kind,
            'service' => $service, 'targetRepository' => 'ulaxys-platform/ulaxys-deploy',
            'targetPath' => $path, 'mode' => 'manual-review-merge', 'patch' => $patch,
        ];
    }
}
