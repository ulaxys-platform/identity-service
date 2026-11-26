<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Tests\Fixtures\DomainExample;

use OpenApi\Attributes as OA;
use Ulaxys\Identity\Transformers\HttpSuccessResponseObjectOfContract;

#[OA\Schema(
    schema: 'ExampleLabelListResponse',
    required: ['size', 'status', 'message', 'items', 'pagination'],
    properties: [
        new OA\Property(property: 'size', type: 'integer'),
        new OA\Property(property: 'status', type: 'integer'),
        new OA\Property(property: 'message', type: 'string'),
        new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/ExampleLabelDto')),
        new OA\Property(property: 'pagination', type: 'object', required: ['limit', 'offset', 'total'], properties: [
            new OA\Property(property: 'limit', type: 'integer'),
            new OA\Property(property: 'offset', type: 'integer'),
            new OA\Property(property: 'total', type: 'integer'),
        ]),
    ],
)]
final class ListExampleLabelsResponse extends HttpSuccessResponseObjectOfContract
{
    /** @var list<ExampleLabelDto> */
    public array $items = [];

    /** @var array{limit: int, offset: int, total: int} */
    public array $pagination = [];

    /** @param list<ExampleLabelDto> $items */
    public function withPage(array $items, int $limit, int $offset, int $total): self
    {
        $this->items = $items;
        $this->pagination = compact('limit', 'offset', 'total');

        return $this;
    }
}
