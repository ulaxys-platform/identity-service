<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Http\Requests\Identity;

use OpenApi\Attributes as OA;
use Ulaxys\Identity\Http\Requests\ApiFormRequest;

#[OA\Schema(
    schema: 'IdentityAccountSetupInput',
    required: ['token', 'newPassword'],
    additionalProperties: false,
    properties: [
        new OA\Property(property: 'token', type: 'string', description: 'Single-use bootstrap setup token'),
        new OA\Property(property: 'newPassword', type: 'string', maxLength: 128),
    ],
)]
final class AccountSetupRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'min:32', 'max:256'],
            'newPassword' => ['required', 'string', 'min:12', 'max:128'],
        ];
    }
}
