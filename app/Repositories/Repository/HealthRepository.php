<?php

namespace Ulaxys\Identity\Repositories\Repository;

use Illuminate\Database\DatabaseManager;
use Ulaxys\Identity\Repositories\RepositoryInterface\HealthRepositoryInterface;

final class HealthRepository implements HealthRepositoryInterface
{
    public function __construct(private DatabaseManager $database) {}

    public function probe(): void
    {
        $this->database->connection()->selectOne('SELECT 1 AS ready', [], false);
    }
}
