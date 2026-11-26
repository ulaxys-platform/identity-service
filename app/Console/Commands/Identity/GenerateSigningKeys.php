<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Console\Commands\Identity;

use Illuminate\Console\Command;
use Throwable;
use Ulaxys\Identity\Services\Identity\Tokens\SigningKeyService;

final class GenerateSigningKeys extends Command
{
    protected $signature = 'identity:keys:generate
        {--rotate : Retire the current active key and publish a new one with JWKS overlap}
        {--force : Generate even when an active key exists (implies --rotate)}';

    protected $description = 'Generate or rotate Identity RS256 signing keys (operator command; never exposed over HTTP)';

    public function handle(SigningKeyService $service): int
    {
        $rotate = (bool) ($this->option('rotate') || $this->option('force'));
        try {
            $result = $service->generate($rotate);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->info('Signing key published.');
        $this->line('kid: '.$result['kid']);
        $this->line('private key: '.$result['private_path'].' (mode 0600, never committed, never served)');
        $this->line('public key:  '.$result['public_path']);

        return self::SUCCESS;
    }
}
