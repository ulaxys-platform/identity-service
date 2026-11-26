<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Dto\Identity;

use OpenApi\Attributes as OA;
use Ulaxys\Identity\Dto\BaseDto;

/** Whitelist of public user fields; internal audit columns never escape. */
#[OA\Schema(
    schema: 'IdentityUserDto',
    required: ['id', 'status', 'type', 'displayName', 'preferredLanguage', 'preferredTimezone', 'createdAt', 'updatedAt'],
    additionalProperties: false,
    properties: [
        new OA\Property(property: 'id', type: 'string', description: 'Opaque stable user identifier (ULID)'),
        new OA\Property(property: 'status', type: 'string', enum: ['invited', 'pending_verification', 'active', 'suspended', 'disabled', 'archived']),
        new OA\Property(property: 'type', type: 'string', enum: ['human', 'service']),
        new OA\Property(property: 'displayName', type: 'string'),
        new OA\Property(property: 'firstName', type: 'string', nullable: true),
        new OA\Property(property: 'lastName', type: 'string', nullable: true),
        new OA\Property(property: 'preferredLanguage', type: 'string'),
        new OA\Property(property: 'preferredTimezone', type: 'string'),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time'),
    ],
)]
final class UserDto extends BaseDto
{
    public string $id;

    public string $status;

    public string $type;

    public string $displayName;

    public ?string $firstName;

    public ?string $lastName;

    public string $preferredLanguage;

    public string $preferredTimezone;

    public string $createdAt;

    public string $updatedAt;
}
