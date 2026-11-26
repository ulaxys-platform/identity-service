<?php

namespace Ulaxys\Identity\Providers;

use Illuminate\Support\ServiceProvider;
use Ulaxys\Identity\Repositories\Repository\HealthRepository;
use Ulaxys\Identity\Repositories\RepositoryInterface\HealthRepositoryInterface;

final class AppServiceProvider extends ServiceProvider
{
    public $bindings = [HealthRepositoryInterface::class => HealthRepository::class];
}
