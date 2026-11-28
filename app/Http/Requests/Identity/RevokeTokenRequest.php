<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Http\Requests\Identity;

use OpenApi\Attributes as OA;
use Ulaxys\Identity\Http\Requests\ApiFormRequest;

#[OA\Schema(
    schema: 'IdentityRevokeInput',
    required: ['token'],
    additionalProperties: false,
    properties: [
        new OA\Property(property: 'token', type: 'string', description: 'Access token to revoke (RFC 7009)'),
    ],
)]
final class RevokeTokenRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'min:16', 'max:4096'],
        ];
    }
}
