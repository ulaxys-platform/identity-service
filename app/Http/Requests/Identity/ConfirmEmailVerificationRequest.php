<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Http\Requests\Identity;

use OpenApi\Attributes as OA;
use Ulaxys\Identity\Http\Requests\ApiFormRequest;

#[OA\Schema(
    schema: 'IdentityConfirmEmailVerificationInput',
    required: ['token'],
    additionalProperties: false,
    properties: [
        new OA\Property(property: 'token', type: 'string'),
    ],
)]
final class ConfirmEmailVerificationRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'min:32', 'max:256'],
        ];
    }
}
