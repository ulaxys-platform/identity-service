<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Transformers\Identity;

use OpenApi\Attributes as OA;
use Ulaxys\Identity\Dto\Identity\UserDto;
use Ulaxys\Identity\Transformers\HttpSuccessResponseObjectOfContract;

#[OA\Schema(
    schema: 'IdentityUserCreatedResponse',
    required: ['size', 'status', 'message', 'items'],
    properties: [
        new OA\Property(property: 'size', type: 'integer'),
        new OA\Property(property: 'status', type: 'integer'),
        new OA\Property(property: 'message', type: 'string'),
        new OA\Property(property: 'items', ref: '#/components/schemas/IdentityUserDto'),
    ],
)]
final class UserCreatedResponse extends HttpSuccessResponseObjectOfContract
{
    public UserDto $items;

    public function withItem(UserDto $item): self
    {
        $this->items = $item;

        return $this;
    }
}
