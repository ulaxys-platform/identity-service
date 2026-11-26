<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Dto\Identity;

use OpenApi\Attributes as OA;
use Ulaxys\Identity\Dto\BaseDto;

#[OA\Schema(
    schema: 'IdentityAuthSessionDto',
    required: ['id', 'authenticationMethods', 'isCurrent', 'expiresAt', 'createdAt'],
    additionalProperties: false,
    properties: [
        new OA\Property(property: 'id', type: 'string'),
        new OA\Property(property: 'deviceLabel', type: 'string', nullable: true),
        new OA\Property(property: 'authenticationMethods', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'isCurrent', type: 'boolean'),
        new OA\Property(property: 'mfaCompletedAt', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'lastSeenAt', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'expiresAt', type: 'string', format: 'date-time'),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
    ],
)]
final class AuthSessionDto extends BaseDto
{
    public string $id;

    public ?string $deviceLabel;

    /** @var list<string> */
    public array $authenticationMethods;

    public bool $isCurrent;

    public ?string $mfaCompletedAt;

    public ?string $lastSeenAt;

    public string $expiresAt;

    public string $createdAt;
}
