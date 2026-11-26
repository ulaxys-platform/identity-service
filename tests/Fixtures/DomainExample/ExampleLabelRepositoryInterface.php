<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Tests\Fixtures\DomainExample;

interface ExampleLabelRepositoryInterface
{
    /** @return array{0: list<ExampleLabel>, 1: int} */
    public function page(int $limit, int $offset): array;

    public function codeExists(string $code): bool;

    public function create(string $code, array $title): ExampleLabel;
}
