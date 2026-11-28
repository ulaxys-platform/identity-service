<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Presenters\Mapper\Identity;

use Ulaxys\Identity\Dto\Identity\AuthSessionDto;
use Ulaxys\Identity\Models\AuthSession;

final class AuthSessionMapper
{
    public function map(AuthSession $session, bool $isCurrent): AuthSessionDto
    {
        $dto = new AuthSessionDto;
        $dto->id = (string) $session->id;
        $dto->deviceLabel = $session->device_label;
        $dto->authenticationMethods = $session->authentication_methods ?? ['password'];
        $dto->isCurrent = $isCurrent;
        $dto->mfaCompletedAt = $session->mfa_completed_at?->toIso8601String();
        $dto->lastSeenAt = $session->last_seen_at?->toIso8601String();
        $dto->expiresAt = (string) $session->expires_at?->toIso8601String();
        $dto->createdAt = (string) $session->created_at?->toIso8601String();

        return $dto;
    }
}
