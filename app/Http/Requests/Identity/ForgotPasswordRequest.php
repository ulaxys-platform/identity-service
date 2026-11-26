<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Http\Requests\Identity;

use OpenApi\Attributes as OA;
use Ulaxys\Identity\Http\Requests\ApiFormRequest;

#[OA\Schema(
    schema: 'IdentityForgotPasswordInput',
    required: ['identifier'],
    additionalProperties: false,
    properties: [
        new OA\Property(property: 'identifier', type: 'string', maxLength: 255),
    ],
)]
final class ForgotPasswordRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string', 'min:1', 'max:255'],
        ];
    }
}
