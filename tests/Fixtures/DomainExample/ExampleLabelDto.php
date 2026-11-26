<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Tests\Fixtures\DomainExample;

use OpenApi\Attributes as OA;
use Ulaxys\Identity\Dto\BaseDto;

/** Whitelist of public API fields; the model and its internal_note never escape. */
#[OA\Schema(
    schema: 'ExampleLabelDto',
    required: ['id', 'code', 'title', 'active'],
    additionalProperties: false,
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'code', type: 'string'),
        new OA\Property(property: 'title', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string')),
        new OA\Property(property: 'active', type: 'boolean'),
    ],
)]
final class ExampleLabelDto extends BaseDto
{
    public int $id;

    public string $code;

    public object $title;

    public bool $active;
}
