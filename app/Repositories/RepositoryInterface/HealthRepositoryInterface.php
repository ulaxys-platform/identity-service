<?php

namespace Ulaxys\Identity\Repositories\RepositoryInterface;

interface HealthRepositoryInterface
{
    public function probe(): void;
}
