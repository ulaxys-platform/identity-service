<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Transformers\Identity;

use OpenApi\Attributes as OA;
use Ulaxys\Identity\Dto\Identity\AuthSessionDto;
use Ulaxys\Identity\Transformers\HttpSuccessResponseObjectOfContract;

#[OA\Schema(
    schema: 'IdentitySessionListResponse',
    required: ['size', 'status', 'message', 'items'],
    properties: [
        new OA\Property(property: 'size', type: 'integer'),
        new OA\Property(property: 'status', type: 'integer'),
        new OA\Property(property: 'message', type: 'string'),
        new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/IdentityAuthSessionDto')),
    ],
)]
final class SessionListResponse extends HttpSuccessResponseObjectOfContract
{
    /** @var list<AuthSessionDto> */
    public array $items = [];

    /** @param list<AuthSessionDto> $items */
    public function withItems(array $items): self
    {
        $this->items = $items;

        return $this;
    }
}
