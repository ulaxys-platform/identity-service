<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Http\Middleware\Identity;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Ulaxys\Identity\Integration\SecurityMiddleware;
use Ulaxys\Identity\Repositories\RepositoryInterface\UserRepositoryInterface;
use Ulaxys\Identity\Services\Identity\Provider\ProviderFailure;
use Ulaxys\Identity\Services\Identity\Provider\VerifyProviderAccessToken;
use Ulaxys\Identity\Transformers\ErrorResponseObjects\HttpErrorResponseObjectOfResult;

#[SecurityMiddleware('actor')]
final class VerifyActorToken
{
    public function __construct(
        private readonly VerifyProviderAccessToken $provider,
        private readonly UserRepositoryInterface $users,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if (! is_string($token) || $token === '') {
            return $this->unauthorized();
        }
        try {
            $claims = $this->provider->verify($token);
        } catch (ProviderFailure) {
            return response()->json((new HttpErrorResponseObjectOfResult)
                ->setStatus(503)->setCode(503)->setMessage('Authentication dependency unavailable.')->toArray(), 503);
        }
        if ($claims === null) {
            return $this->unauthorized();
        }

        $userId = DB::table('identity_provider_mappings')
            ->where(['issuer' => $claims['iss'], 'subject' => $claims['sub'], 'state' => 'active'])
            ->value('user_id');
        $user = is_string($userId) ? $this->users->findById($userId) : null;
        if ($user === null || ! $user->isLoginEligible()) {
            return $this->unauthorized();
        }

        $request->attributes->set('actor.user', $user);
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
