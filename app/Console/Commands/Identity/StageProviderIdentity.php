<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Console\Commands\Identity;

use Illuminate\Console\Command;
use Ulaxys\Identity\Services\Identity\Provider\ProviderFailure;
use Ulaxys\Identity\Services\Identity\Provider\ProvisionProviderIdentity;

final class StageProviderIdentity extends Command
{
    protected $signature = 'identity:provider-stage {request : Stable provisioning request ULID}';

    protected $description = 'Stage or reconcile a disabled Keycloak account for an invited platform user';

    public function handle(ProvisionProviderIdentity $provisioning): int
    {
        try {
            $this->line($provisioning->stage((string) $this->argument('request')));

            return self::SUCCESS;
        } catch (ProviderFailure $failure) {
            $this->error($failure->getMessage());

            return self::FAILURE;
        }
    }
}
