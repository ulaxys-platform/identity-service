<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Http\Requests\Identity;

use OpenApi\Attributes as OA;
use Ulaxys\Identity\Http\Requests\ApiFormRequest;

#[OA\Schema(
    schema: 'IdentityResetPasswordInput',
    required: ['token', 'newPassword'],
    additionalProperties: false,
    properties: [
        new OA\Property(property: 'token', type: 'string', description: 'Single-use hashed-token challenge secret'),
        new OA\Property(property: 'newPassword', type: 'string', maxLength: 128),
    ],
)]
final class ResetPasswordRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'min:32', 'max:256'],
            'newPassword' => ['required', 'string', 'min:12', 'max:128'],
        ];
    }
}
