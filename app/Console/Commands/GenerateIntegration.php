<?php

namespace Ulaxys\Identity\Console\Commands;

final class GenerateIntegration extends IntegrationCommand
{
    protected $signature = 'ulaxys:generate-integration'.self::OPTIONS.' {--gateway : Generate gateway manifests} {--permissions : Generate permission declarations} {--deploy : Generate manual ulaxys-deploy package} {--all : Generate gateway, permissions and deploy (default)}';

    protected $description = 'Generate all selected integration declarations safely';

    protected array $targets = ['gateway', 'permissions', 'deploy'];
}
