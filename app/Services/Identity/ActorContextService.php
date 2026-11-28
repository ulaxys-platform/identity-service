<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Services\Identity;

use Ulaxys\Identity\Dto\Identity\ActorContextDto;
use Ulaxys\Identity\Models\AuthSession;
use Ulaxys\Identity\Models\User;

/** Builds the versioned actor-context contract payload (docs/gateway-integration.md). */
final class ActorContextService
{
    public function forProviderActor(User $user, array $claims): ActorContextDto
    {
        return new ActorContextDto(
            actorId: (string) $user->id,
            actorType: 'user',
            sessionId: $claims['sid'],
            organizationId: null,
            authenticationMethods: array_values(array_filter((array) ($claims['amr'] ?? []), 'is_string')),
            authorizationVersion: (int) config('identity.actor_context.version', 1),
            providerIssuer: $claims['iss'],
            providerSubject: $claims['sub'],
        );
    }

    public function forActor(User $user, AuthSession $session): ActorContextDto
    {
        return new ActorContextDto(
            actorId: (string) $user->id,
            actorType: 'user',
            sessionId: (string) $session->id,
            organizationId: null, // Organization context arrives with unit I3.
            authenticationMethods: $session->authentication_methods ?? ['password'],
            authorizationVersion: (int) config('identity.actor_context.version', 1),
        );
    }
}
