<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Tests\Fixtures\DomainExample;

final class ExampleLabelMapper
{
    public function map(ExampleLabel $model): ExampleLabelDto
    {
        return ExampleLabelDto::fromArray([
            'id' => (int) $model->id,
            'code' => (string) $model->code,
            'title' => (object) $model->title,
            'active' => (bool) $model->active,
        ]);
    }
}
