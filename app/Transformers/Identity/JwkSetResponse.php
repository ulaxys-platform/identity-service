<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Transformers\Identity;

use OpenApi\Attributes as OA;
use Ulaxys\Identity\Dto\Identity\JwkSetDto;
use Ulaxys\Identity\Transformers\HttpSuccessResponseObjectOfContract;

#[OA\Schema(
    schema: 'IdentityJwkSetResponse',
    required: ['size', 'status', 'message', 'items'],
    properties: [
        new OA\Property(property: 'size', type: 'integer'),
        new OA\Property(property: 'status', type: 'integer'),
        new OA\Property(property: 'message', type: 'string'),
        new OA\Property(property: 'items', ref: '#/components/schemas/IdentityJwkSetDto'),
    ],
)]
final class JwkSetResponse extends HttpSuccessResponseObjectOfContract
{
    public JwkSetDto $items;

    public function withItem(JwkSetDto $item): self
    {
        $this->items = $item;

        return $this;
    }
}
