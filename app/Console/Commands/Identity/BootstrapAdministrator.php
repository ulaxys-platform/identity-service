<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Console\Commands\Identity;

use Illuminate\Console\Command;
use Throwable;
use Ulaxys\Identity\Services\Identity\Bootstrap\BootstrapAdministratorService;

final class BootstrapAdministrator extends Command
{
    protected $signature = 'identity:bootstrap-admin
        {identifier : Email or username login identifier of the first platform administrator}
        {--display-name= : Display name for the operator profile (defaults to the identifier)}
        {--ttl-minutes=1440 : Setup token lifetime in minutes}
        {--output= : Write the setup token to this file (mode 0600) instead of printing only}';

    protected $description = 'Idempotent, auditable bootstrap of the first platform administrator (no seeded passwords)';

    public function handle(BootstrapAdministratorService $service): int
    {
        $identifier = trim((string) $this->argument('identifier'));
        if ($identifier === '') {
            $this->error('An identifier is required.');

            return self::FAILURE;
        }
        $displayName = (string) ($this->option('display-name') ?: $identifier);
        $ttl = (int) $this->option('ttl-minutes');
        try {
            $result = $service->bootstrap($identifier, $displayName, $ttl);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        if (! $result['created']) {
            $this->info('Platform administrator already exists for this identifier; nothing changed (idempotent bootstrap).');
            $this->line('user: '.$result['user_id']);

            return self::SUCCESS;
        }
        $token = (string) $result['token'];
        $outputPath = (string) $this->option('output');
        if ($outputPath !== '') {
            file_put_contents($outputPath, $token.PHP_EOL);
            chmod($outputPath, 0600);
            $this->line('Setup token written (once) to '.$outputPath.' (mode 0600).');
        } else {
            $this->warn('Setup token (printed once, never stored in the database):');
            $this->line($token);
        }
        $this->line('user: '.$result['user_id']);
        $this->line('expires: '.($result['expires_at']?->toIso8601String() ?? ''));
        $this->line('The operator activates the account with POST /api/v1/auth/setup using this single-use token.');

        return self::SUCCESS;
    }
}
