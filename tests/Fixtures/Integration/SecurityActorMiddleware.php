<?php

namespace Ulaxys\Identity\Tests\Fixtures\Integration;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Ulaxys\Identity\Integration\SecurityMiddleware;

#[SecurityMiddleware('actor')]
final class SecurityActorMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        abort(401); // Test-only deny-all middleware; never a production verifier.
    }
}
