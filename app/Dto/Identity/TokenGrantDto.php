<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Dto\Identity;

use OpenApi\Attributes as OA;
use Ulaxys\Identity\Dto\BaseDto;

#[OA\Schema(
    schema: 'IdentityTokenGrantDto',
    required: ['accessToken', 'tokenType', 'expiresIn', 'refreshToken', 'refreshTokenExpiresIn'],
    additionalProperties: false,
    properties: [
        new OA\Property(property: 'accessToken', type: 'string', description: 'RS256 JWT access token'),
        new OA\Property(property: 'tokenType', type: 'string', enum: ['Bearer']),
        new OA\Property(property: 'expiresIn', type: 'integer'),
        new OA\Property(property: 'refreshToken', type: 'string', description: 'Opaque single-use rotating refresh token'),
        new OA\Property(property: 'refreshTokenExpiresIn', type: 'integer'),
    ],
)]
final class TokenGrantDto extends BaseDto
{
    public function __construct(
        public readonly string $accessToken,
        public readonly string $tokenType,
        public readonly int $expiresIn,
        public readonly string $refreshToken,
        public readonly int $refreshTokenExpiresIn,
    ) {}
}
