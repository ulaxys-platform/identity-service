<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Transformers\Identity;

use OpenApi\Attributes as OA;
use Ulaxys\Identity\Dto\Identity\ActorContextDto;
use Ulaxys\Identity\Transformers\HttpSuccessResponseObjectOfContract;

#[OA\Schema(
    schema: 'IdentityActorContextResponse',
    required: ['size', 'status', 'message', 'items'],
    properties: [
        new OA\Property(property: 'size', type: 'integer'),
        new OA\Property(property: 'status', type: 'integer'),
        new OA\Property(property: 'message', type: 'string'),
        new OA\Property(property: 'items', ref: '#/components/schemas/IdentityActorContextDto'),
    ],
)]
final class ActorContextResponse extends HttpSuccessResponseObjectOfContract
{
    public ActorContextDto $items;

    public function withItem(ActorContextDto $item): self
    {
        $this->items = $item;

        return $this;
    }
}
