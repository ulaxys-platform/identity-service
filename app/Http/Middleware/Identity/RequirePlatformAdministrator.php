<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Http\Middleware\Identity;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Ulaxys\Identity\Models\User;
use Ulaxys\Identity\Repositories\RepositoryInterface\PlatformAdministratorRepositoryInterface;
use Ulaxys\Identity\Transformers\ErrorResponseObjects\HttpErrorResponseObjectOfResult;

/**
 * Interim I1 administrative gate (ADR-0004): deny-by-default, requires an
 * active platform administrator grant created only by the auditable
 * identity:bootstrap-admin command. Replaced by the I3 RBAC engine.
 */
final class RequirePlatformAdministrator
{
    public function __construct(private readonly PlatformAdministratorRepositoryInterface $administrators) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->attributes->get('actor.user');
        if (! $user instanceof User) {
            return $this->error(401, (string) trans('identity.auth.authentication_required'));
        }
        if ($this->administrators->findActiveByUserId((string) $user->id) === null) {
            // Identical response whether the account is unknown or unprivileged.
            return $this->error(403, 'Forbidden.');
        }

        return $next($request);
    }

    private function error(int $status, string $message): Response
    {
        return response()
            ->json((new HttpErrorResponseObjectOfResult)->setStatus($status)->setCode($status)->setMessage($message)->toArray(), $status);
    }
}
