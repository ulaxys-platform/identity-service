<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Console\Commands\Identity;

use Illuminate\Console\Command;
use Ulaxys\Identity\Services\Identity\Provider\ManageProviderIdentity;
use Ulaxys\Identity\Services\Identity\Provider\ProviderFailure;

final class ManageProviderAccount extends Command
{
    protected $signature = 'identity:provider-account {operation : invite, activate, suspend, disable or bootstrap} {user : Platform user ULID}';

    protected $description = 'Confirm Keycloak activation or reconcile provider account disablement';

    public function handle(ManageProviderIdentity $accounts): int
    {
        $id = strtolower((string) $this->argument('user'));
        try {
            match ($this->argument('operation')) {
                'invite' => $accounts->invite($id),
                'activate' => $accounts->activate($id, false),
                'bootstrap' => $accounts->activate($id, true),
                'suspend' => $accounts->disable($id, true),
                'disable' => $accounts->disable($id),
                default => throw new ProviderFailure('Unknown account operation.'),
            };
        } catch (ProviderFailure $failure) {
            $this->error($failure->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
