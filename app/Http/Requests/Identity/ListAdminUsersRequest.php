<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Http\Requests\Identity;

use OpenApi\Attributes as OA;
use Ulaxys\Identity\Http\Requests\ApiFormRequest;

#[OA\Schema(
    schema: 'IdentityListUsersInput',
    additionalProperties: false,
    properties: [
        new OA\Property(property: 'limit', type: 'integer', minimum: 1, maximum: 100),
        new OA\Property(property: 'offset', type: 'integer', minimum: 0),
        new OA\Property(property: 'status', type: 'string', enum: ['invited', 'pending_verification', 'active', 'suspended', 'disabled', 'archived']),
    ],
)]
final class ListAdminUsersRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'offset' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'status' => ['sometimes', 'string', 'in:invited,pending_verification,active,suspended,disabled,archived'],
        ];
    }
}
