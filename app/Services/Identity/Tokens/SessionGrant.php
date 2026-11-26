<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Services\Identity\Tokens;

use Ulaxys\Identity\Dto\Identity\TokenGrantDto;
use Ulaxys\Identity\Models\AuthSession;
use Ulaxys\Identity\Models\User;

/** Token grant bound to the verified user and session it belongs to. */
final class SessionGrant
{
    public function __construct(
        public readonly TokenGrantDto $grant,
        public readonly User $user,
        public readonly AuthSession $session,
    ) {}
}
