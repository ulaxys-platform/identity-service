<?php

namespace Ulaxys\Identity\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Routing\Router;
use Ulaxys\Identity\Integration\DeployPackageRenderer;
use Ulaxys\Identity\Integration\GatewayManifestGenerator;
use Ulaxys\Identity\Integration\IntegrationException;
use Ulaxys\Identity\Integration\IntegrationMetadata;
use Ulaxys\Identity\Integration\NativeIdentityRenderer;
use Ulaxys\Identity\Integration\NativeMainGateRenderer;
use Ulaxys\Identity\Integration\OutputWriter;
use Ulaxys\Identity\Integration\PermissionDeclarationGenerator;
use Ulaxys\Identity\Integration\PermissionMapper;
use Ulaxys\Identity\Integration\RouteDiscovery;
use Ulaxys\Identity\Integration\SecurityReviewRenderer;

abstract class IntegrationCommand extends Command
{
    protected const OPTIONS = ' {--permission-format=auto : Permission output: auto, both, json or native} {--gateway-format=both : Gateway output: both, json or native} {--strict : Reject unused permission declarations} {--dry-run : Preview changes without writing} {--check : Fail on missing or stale output without writing} {--output=bundle : Bundle directory relative to generated/integration}';

    protected array $targets;

    public function handle(Router $router, RouteDiscovery $discovery, IntegrationMetadata $metadata, PermissionMapper $mapper,
        GatewayManifestGenerator $gateway, PermissionDeclarationGenerator $permissions, OutputWriter $writer, NativeMainGateRenderer $native, NativeIdentityRenderer $identity, DeployPackageRenderer $deploy): int
    {
        try {
            if ($this->option('dry-run') && $this->option('check')) {
                throw new IntegrationException('--dry-run and --check cannot be combined.');
            }
            $targets = $this->targets;
            if ($this instanceof GenerateIntegration) {
                $selection = array_filter(['gateway' => $this->option('gateway'), 'permissions' => $this->option('permissions'), 'deploy' => $this->option('deploy')]);
                if ($this->option('all') && $selection !== []) {
                    throw new IntegrationException('Use --all or individual selection flags, not both.');
                }
                if ($selection !== []) {
                    $targets = array_keys($selection);
                }
            }
            $config = $metadata->validate(config('integration', []));
            $snapshot = $mapper->map($discovery->collect($router, $config), $config, (bool) $this->option('strict'));
            if ($config['schemaVersion'] === 2) {
                if (in_array('gateway', $targets, true) && $this->option('gateway-format') !== 'json') {
                    throw new IntegrationException('Security contract v2 has no verified native Gateway target; select --gateway-format=json for review-only output.');
                }
                if (in_array('permissions', $targets, true) && ! in_array($this->option('permission-format'), ['auto', 'json'], true)) {
                    throw new IntegrationException('Security contract v2 has no verified native Identity target; select --permission-format=json.');
                }
                if ((string) $this->option('output') === 'bundle') {
                    throw new IntegrationException('Security contract v2 requires a separate explicit --output review directory.');
                }
                $files = (new SecurityReviewRenderer)->generate($snapshot, $targets);
                $changes = $writer->apply($files, (string) $this->option('output'), (bool) $this->option('dry-run'), (bool) $this->option('check'));
                foreach ($changes as $path => $status) {
                    $this->line($status.' '.$path);
                }
                foreach ($snapshot['warnings'] as $warning) {
                    $this->warn($warning);
                }
                $this->warn('Security contract v2 review only: no executable Gateway, Identity or Deploy output; downstream support not verified.');

                return $this->option('check') && array_filter($changes, fn ($status) => $status !== 'unchanged') ? self::FAILURE : self::SUCCESS;
            }
            $files = [];
            if (in_array('gateway', $targets, true)) {
                $format = $this->option('gateway-format');
                if (! in_array($format, ['both', 'json', 'native'], true)) {
                    throw new IntegrationException('Unsupported --gateway-format; use both, json or native.');
                }
                $files += $gateway->generate($snapshot, $config);
                if ($format !== 'json') {
                    $nativeFiles = $native->generate($snapshot, $config);
                    if (array_intersect_key($nativeFiles, $files)) {
                        throw new IntegrationException('Native/declaration output collision.');
                    }
                    $files += $nativeFiles;
                    $files['gateway/compatibility.json']['nativeMainGate'] = ['format' => 'Inventory-style PHP', 'runtimeVerified' => false];
                }
                if ($format === 'native') {
                    unset($files[$config['gateway']['mainGate']['file']], $files[$config['gateway']['serviceGateway']['file']]);
                }
            }
            if (in_array('permissions', $targets, true)) {
                $permissionFormat = $this->option('permission-format');
                if (! in_array($permissionFormat, ['auto', 'both', 'json', 'native'], true)) {
                    throw new IntegrationException('Unsupported --permission-format; use auto, both, json or native.');
                }
                $files += $permissions->generate($snapshot, $config);
                if ($permissionFormat === 'auto') {
                    $permissionFormat = $identity->hasTranslations($snapshot) ? 'both' : 'json';
                    if ($permissionFormat === 'json') {
                        $this->warn('Legacy descriptions/localization keys: JSON only. Configure explicit tr/en/ar action descriptions for native Identity output.');
                    }
                }
                if ($permissionFormat !== 'json') {
                    $identityFiles = $identity->generate($snapshot, $config);
                    if (array_intersect_key($files, $identityFiles)) {
                        throw new IntegrationException('Native Identity/declaration output collision.');
                    }
                    $files += $identityFiles;
                    $files['identity/compatibility.json']['nativeIdentity'] = ['contractVersion' => 1, 'executableSeeder' => true, 'runtimeVerified' => false, 'mutableFields' => ['description']];
                }
                if ($permissionFormat === 'native') {
                    unset($files[$config['permissionsFile']]);
                }
            }
            if (in_array('deploy', $targets, true)) {
                $deployFiles = $deploy->generate($snapshot, $config);
                if (array_intersect_key($files, $deployFiles)) {
                    throw new IntegrationException('Deploy integration output collision.');
                }
                $files += $deployFiles;
            }
            $changes = $writer->apply($files, (string) $this->option('output'), (bool) $this->option('dry-run'), (bool) $this->option('check'));
            foreach ($changes as $path => $status) {
                $this->line($status.' '.$path);
            }
            foreach ($snapshot['warnings'] as $warning) {
                $this->warn($warning);
            }
            if (in_array('deploy', $targets, true)) {
                $this->warn('Deploy package is review-only. Manual copy/merge into ulaxys-deploy and its own validation are mandatory. No ports, secrets, production placement or operator capabilities are installed.');
            }
            $this->warn(isset($files['identity/README.md'])
                ? 'MainGate installation requires runtime verification. Native Identity installation also requires real runtime verification. Service Gateway remains intermediate JSON.'
                : 'MainGate installation requires runtime verification. Identity and Service Gateway remain intermediate declarations only.');

            return $this->option('check') && array_filter($changes, fn ($status) => $status !== 'unchanged') ? self::FAILURE : self::SUCCESS;
        } catch (IntegrationException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
