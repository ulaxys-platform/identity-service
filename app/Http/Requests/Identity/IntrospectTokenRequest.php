<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Http\Requests\Identity;

use OpenApi\Attributes as OA;
use Ulaxys\Identity\Http\Requests\ApiFormRequest;

#[OA\Schema(
    schema: 'IdentityIntrospectInput',
    required: ['token'],
    additionalProperties: false,
    properties: [
        new OA\Property(property: 'token', type: 'string', description: 'Access token to introspect (RFC 7662)'),
    ],
)]
final class IntrospectTokenRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'min:16', 'max:4096'],
        ];
    }
}
