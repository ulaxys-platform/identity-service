<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Http\Middleware\Identity;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Ulaxys\Identity\Integration\SecurityMiddleware;
use Ulaxys\Identity\Repositories\RepositoryInterface\AuthSessionRepositoryInterface;
use Ulaxys\Identity\Repositories\RepositoryInterface\UserRepositoryInterface;
use Ulaxys\Identity\Services\Identity\Tokens\JwtCodec;
use Ulaxys\Identity\Services\Identity\Tokens\SigningKeyService;
use Ulaxys\Identity\Services\Identity\Tokens\TokenException;
use Ulaxys\Identity\Transformers\ErrorResponseObjects\HttpErrorResponseObjectOfResult;

/**
 * Validates an Identity access token and binds the verified actor to the
 * request. Deny-by-default: missing/invalid tokens and unusable sessions are
 * 401; signing-key unavailability is 503 (never silently accepted).
 * Caller-supplied headers claiming actor identity are never trusted.
 */
#[SecurityMiddleware('actor')]
final class VerifyActorToken
{
    public function __construct(
        private readonly JwtCodec $codec,
        private readonly SigningKeyService $signingKeys,
        private readonly UserRepositoryInterface $users,
        private readonly AuthSessionRepositoryInterface $sessions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if (! is_string($token) || $token === '') {
            return $this->unauthorized();
        }
        try {
            $claims = $this->codec->decode($token, $this->signingKeys->verificationKeys(), (string) config('identity.token.audience'));
        } catch (TokenException $exception) {
            if ($exception->reason === TokenException::KEYS_UNAVAILABLE) {
                return response()->json((new HttpErrorResponseObjectOfResult)
                    ->setStatus(503)->setCode(503)->setMessage('Authentication dependency unavailable.')->toArray(), 503);
            }

            return $this->unauthorized();
        }
        $sub = $claims['sub'] ?? null;
        $sid = $claims['sid'] ?? null;
        if (! is_string($sub) || ! is_string($sid) || $sub === '' || $sid === '') {
            return $this->unauthorized();
        }
        $user = $this->users->findById($sub);
        $session = $this->sessions->findById($sid);
        if ($user === null || $session === null || (string) $session->user_id !== $sub || ! $user->isLoginEligible() || ! $session->isUsable()) {
            return $this->unauthorized();
        }
        $this->sessions->touch($sid);
        $request->attributes->set('actor.user', $user);
        $request->attributes->set('actor.session', $session);
        $request->attributes->set('actor.claims', $claims);

        return $next($request);
    }

    private function unauthorized(): Response
    {
        return response()
            ->json((new HttpErrorResponseObjectOfResult)
                ->setStatus(401)->setCode(401)->setMessage((string) trans('identity.auth.authentication_required'))->toArray(), 401)
            ->withHeaders(['WWW-Authenticate' => 'Bearer error="invalid_token"']);
    }
}
