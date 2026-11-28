<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Http\Requests\Identity;

use OpenApi\Attributes as OA;
use Ulaxys\Identity\Http\Requests\ApiFormRequest;

#[OA\Schema(
    schema: 'IdentityProviderLifecycleInput',
    required: ['operation'],
    additionalProperties: false,
    properties: [
        new OA\Property(property: 'operation', type: 'string', enum: ['invite', 'activate', 'reactivate', 'suspend', 'disable']),
    ],
)]
final class ManageProviderAccountRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return ['operation' => ['required', 'string', 'in:invite,activate,reactivate,suspend,disable']];
    }
}
