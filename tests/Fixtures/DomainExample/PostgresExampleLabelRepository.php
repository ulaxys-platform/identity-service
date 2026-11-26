<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Tests\Fixtures\DomainExample;

/** All domain SQL and Eloquent access stays on the repository side. */
final class PostgresExampleLabelRepository implements ExampleLabelRepositoryInterface
{
    public function page(int $limit, int $offset): array
    {
        $query = ExampleLabel::query()->where('active', true);
        $total = (clone $query)->count();

        return [$query->orderBy('id')->limit($limit)->offset($offset)->get()->all(), $total];
    }

    public function codeExists(string $code): bool
    {
        return ExampleLabel::query()->where('code', $code)->exists();
    }

    public function create(string $code, array $title): ExampleLabel
    {
        return ExampleLabel::query()->create(['code' => $code, 'title' => $title, 'active' => true]);
    }
}
