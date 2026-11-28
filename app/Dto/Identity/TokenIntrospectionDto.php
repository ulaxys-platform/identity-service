<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Dto\Identity;

use OpenApi\Attributes as OA;
use Ulaxys\Identity\Dto\BaseDto;

/** RFC 7662-shaped introspection result for the Gateway contract. */
#[OA\Schema(
    schema: 'IdentityTokenIntrospectionDto',
    required: ['active'],
    additionalProperties: false,
    properties: [
        new OA\Property(property: 'active', type: 'boolean'),
        new OA\Property(property: 'sub', type: 'string', nullable: true),
        new OA\Property(property: 'sid', type: 'string', nullable: true),
        new OA\Property(property: 'amr', type: 'array', items: new OA\Items(type: 'string'), nullable: true),
        new OA\Property(property: 'aud', type: 'string', nullable: true),
        new OA\Property(property: 'iss', type: 'string', nullable: true),
        new OA\Property(property: 'exp', type: 'integer', nullable: true),
        new OA\Property(property: 'iat', type: 'integer', nullable: true),
        new OA\Property(property: 'nbf', type: 'integer', nullable: true),
        new OA\Property(property: 'jti', type: 'string', nullable: true),
        new OA\Property(property: 'tokenType', type: 'string', nullable: true),
    ],
)]
final class TokenIntrospectionDto extends BaseDto
{
    public function __construct(
        public readonly bool $active,
        public readonly ?string $sub = null,
        public readonly ?string $sid = null,
        public readonly ?array $amr = null,
        public readonly ?string $aud = null,
        public readonly ?string $iss = null,
        public readonly ?int $exp = null,
        public readonly ?int $iat = null,
        public readonly ?int $nbf = null,
        public readonly ?string $jti = null,
        public readonly ?string $tokenType = null,
    ) {}
}
