<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Http\Requests\Identity;

use OpenApi\Attributes as OA;
use Ulaxys\Identity\Http\Requests\ApiFormRequest;

#[OA\Schema(
    schema: 'IdentityRefreshInput',
    required: ['refreshToken'],
    additionalProperties: false,
    properties: [
        new OA\Property(property: 'refreshToken', type: 'string', description: 'Opaque single-use rotating refresh token'),
    ],
)]
final class RefreshTokenRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'refreshToken' => ['required', 'string', 'min:32', 'max:512'],
        ];
    }
}
