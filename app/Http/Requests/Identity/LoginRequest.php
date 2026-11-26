<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Http\Requests\Identity;

use OpenApi\Attributes as OA;
use Ulaxys\Identity\Http\Requests\ApiFormRequest;

#[OA\Schema(
    schema: 'IdentityLoginInput',
    required: ['identifier', 'password'],
    additionalProperties: false,
    properties: [
        new OA\Property(property: 'identifier', type: 'string', maxLength: 255, description: 'Email or username login identifier'),
        new OA\Property(property: 'password', type: 'string', maxLength: 128),
        new OA\Property(property: 'deviceLabel', type: 'string', maxLength: 120, nullable: true, description: 'Informational only; never used for authentication'),
    ],
)]
final class LoginRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string', 'min:1', 'max:255'],
            'password' => ['required', 'string', 'min:1', 'max:128'],
            'deviceLabel' => ['sometimes', 'string', 'max:120'],
        ];
    }
}
