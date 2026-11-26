<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Repositories\RepositoryInterface;

interface AuditRepositoryInterface
{
    /** Append-only; no update or delete methods exist by design (ADR-0005). */
    public function appendAudit(array $attributes): void;

    public function appendSecurity(array $attributes): void;
}
