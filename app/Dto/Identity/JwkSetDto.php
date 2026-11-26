<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Dto\Identity;

use OpenApi\Attributes as OA;
use Ulaxys\Identity\Dto\BaseDto;

#[OA\Schema(
    schema: 'IdentityJwkSetDto',
    required: ['keys'],
    additionalProperties: false,
    properties: [
        new OA\Property(property: 'keys', type: 'array', items: new OA\Items(type: 'object'), description: 'RFC 7517 JWK set (public signing keys only)'),
    ],
)]
final class JwkSetDto extends BaseDto
{
    /** @param list<array<string, string>> $keys */
    public function __construct(public readonly array $keys) {}
}
