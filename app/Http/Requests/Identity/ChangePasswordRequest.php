<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Http\Requests\Identity;

use OpenApi\Attributes as OA;
use Ulaxys\Identity\Http\Requests\ApiFormRequest;

#[OA\Schema(
    schema: 'IdentityChangePasswordInput',
    required: ['currentPassword', 'newPassword'],
    additionalProperties: false,
    properties: [
        new OA\Property(property: 'currentPassword', type: 'string', maxLength: 128),
        new OA\Property(property: 'newPassword', type: 'string', maxLength: 128, description: 'Policy-checked by the service, not only by validation'),
    ],
)]
final class ChangePasswordRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'currentPassword' => ['required', 'string', 'min:1', 'max:128'],
            'newPassword' => ['required', 'string', 'min:12', 'max:128'],
        ];
    }
}
