<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Dto\Identity;

use OpenApi\Attributes as OA;
use Ulaxys\Identity\Dto\BaseDto;

/** Versioned actor-context contract payload (docs/gateway-integration.md). */
#[OA\Schema(
    schema: 'IdentityActorContextDto',
    required: ['actorId', 'actorType', 'sessionId', 'authenticationMethods', 'authorizationVersion'],
    additionalProperties: false,
    properties: [
        new OA\Property(property: 'actorId', type: 'string', description: 'Opaque stable actor identifier'),
        new OA\Property(property: 'actorType', type: 'string', enum: ['user', 'service'], description: 'service principals arrive in a later unit'),
        new OA\Property(property: 'sessionId', type: 'string'),
        new OA\Property(property: 'organizationId', type: 'string', nullable: true, description: 'Reserved for unit I3; always null in I1'),
        new OA\Property(property: 'authenticationMethods', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'authorizationVersion', type: 'integer'),
        new OA\Property(property: 'providerIssuer', type: 'string', nullable: true),
        new OA\Property(property: 'providerSubject', type: 'string', nullable: true),
    ],
)]
final class ActorContextDto extends BaseDto
{
    public function __construct(
        public readonly string $actorId,
        public readonly string $actorType,
        public readonly string $sessionId,
        public readonly ?string $organizationId,
        public readonly array $authenticationMethods,
        public readonly int $authorizationVersion,
        public readonly ?string $providerIssuer = null,
        public readonly ?string $providerSubject = null,
    ) {}
}
