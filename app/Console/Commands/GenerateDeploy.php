<?php

namespace Ulaxys\Identity\Console\Commands;

final class GenerateDeploy extends IntegrationCommand
{
    protected $signature = 'ulaxys:generate-deploy'.self::OPTIONS;

    protected $description = 'Generate manual-review ulaxys-deploy integration package, without installing it';

    protected array $targets = ['deploy'];
}
