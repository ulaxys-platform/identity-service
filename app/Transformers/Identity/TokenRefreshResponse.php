<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Transformers\Identity;

use OpenApi\Attributes as OA;
use Ulaxys\Identity\Dto\Identity\ActorContextDto;
use Ulaxys\Identity\Dto\Identity\TokenGrantDto;
use Ulaxys\Identity\Transformers\HttpSuccessResponseObject;

#[OA\Schema(
    schema: 'IdentityTokenRefreshResponse',
    required: ['size', 'status', 'message', 'tokens', 'actor'],
    properties: [
        new OA\Property(property: 'size', type: 'integer'),
        new OA\Property(property: 'status', type: 'integer'),
        new OA\Property(property: 'message', type: 'string'),
        new OA\Property(property: 'tokens', ref: '#/components/schemas/IdentityTokenGrantDto'),
        new OA\Property(property: 'actor', ref: '#/components/schemas/IdentityActorContextDto'),
    ],
)]
final class TokenRefreshResponse extends HttpSuccessResponseObject
{
    private TokenGrantDto $tokens;

    private ActorContextDto $actor;

    public function withGrant(TokenGrantDto $tokens, ActorContextDto $actor): self
    {
        $this->tokens = $tokens;
        $this->actor = $actor;

        return $this;
    }

    public function toArray(): array
    {
        return [...parent::toArray(), 'tokens' => $this->tokens->toArray(), 'actor' => $this->actor->toArray()];
    }
}
