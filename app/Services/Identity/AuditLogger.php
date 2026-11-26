<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Services\Identity;

use Ulaxys\Identity\Repositories\RepositoryInterface\AuditRepositoryInterface;

/**
 * Append-only audit and security event writer (ADR-0005).
 *
 * Callers must never pass passwords, raw tokens, private keys, TOTP seeds or
 * recovery codes; context payloads are built from explicit whitelist fields.
 * IP addresses and user agents are stored only as keyed SHA-256 hashes.
 */
final class AuditLogger
{
    public function __construct(private readonly AuditRepositoryInterface $repository) {}

    public function audit(string $action, ?string $actorId, string $actorType, ?string $subjectType, ?string $subjectId, string $result, array $context = [], ?string $correlationId = null): void
    {
        $this->repository->appendAudit([
            'actor_id' => $actorId,
            'actor_type' => $actorType,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'result' => $result,
            'correlation_id' => $correlationId,
            'context' => $context === [] ? null : $context,
            'occurred_at' => now(),
        ]);
    }

    public function security(string $eventType, ?string $userId, ?string $sessionId, string $result, array $context = [], ?string $ipHash = null): void
    {
        $this->repository->appendSecurity([
            'event_type' => $eventType,
            'user_id' => $userId,
            'session_id' => $sessionId,
            'result' => $result,
            'ip_hash' => $ipHash,
            'context' => $context === [] ? null : $context,
            'occurred_at' => now(),
        ]);
    }

    /** Keyed hash for IP/user-agent values; the pepper comes from APP_KEY. */
    public static function hashWithPepper(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }
}
