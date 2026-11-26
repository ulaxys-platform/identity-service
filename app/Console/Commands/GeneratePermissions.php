<?php

namespace Ulaxys\Identity\Console\Commands;

final class GeneratePermissions extends IntegrationCommand
{
    protected $signature = 'ulaxys:generate-permissions'.self::OPTIONS;

    protected $description = 'Generate an intermediate Identity permission declaration, not an executable seeder';

    protected array $targets = ['permissions'];
}
