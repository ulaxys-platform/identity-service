<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Repositories\Repository;

use Ulaxys\Identity\Models\AuditEvent;
use Ulaxys\Identity\Models\SecurityEvent;
use Ulaxys\Identity\Repositories\RepositoryInterface\AuditRepositoryInterface;

final class AuditRepository implements AuditRepositoryInterface
{
    public function appendAudit(array $attributes): void
    {
        AuditEvent::query()->create($attributes);
    }

    public function appendSecurity(array $attributes): void
    {
        SecurityEvent::query()->create($attributes);
    }
}
