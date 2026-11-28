<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Console\Commands\Identity;

use Illuminate\Console\Command;
use Ulaxys\Identity\Services\Identity\Provider\ProviderFailure;
use Ulaxys\Identity\Services\Identity\Provider\ProvisioningConflict;
use Ulaxys\Identity\Services\Identity\Provider\ProvisionProviderIdentity;

final class StageProviderIdentity extends Command
{
    protected $signature = 'identity:provider-stage {request : Stable provisioning request ULID} {--email= : Contact email for Keycloak activation}';

    protected $description = 'Stage or reconcile a disabled Keycloak account for an invited platform user';

    public function handle(ProvisionProviderIdentity $provisioning): int
    {
        try {
            $email = $this->option('email');
            if ($email !== null && (! is_string($email) || strlen($email) > 255 || ! filter_var($email, FILTER_VALIDATE_EMAIL))) {
                throw new ProviderFailure('A valid contact email is required.');
            }
            $this->line($provisioning->stage((string) $this->argument('request'), email: $email));

            return self::SUCCESS;
        } catch (ProviderFailure|ProvisioningConflict $failure) {
            $this->error($failure->getMessage());

            return self::FAILURE;
        }
    }
}
