<?php

namespace Ulaxys\Identity\Console\Commands;

final class GenerateGateway extends IntegrationCommand
{
    protected $signature = 'ulaxys:generate-gateway'.self::OPTIONS;

    protected $description = 'Generate separate intermediate PHP gateway route manifests';

    protected array $targets = ['gateway'];
}
