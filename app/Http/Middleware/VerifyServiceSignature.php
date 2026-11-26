<?php

namespace Ulaxys\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Ulaxys\Identity\Transformers\ErrorResponseObjects\HttpErrorResponseObjectOfResult;

final class VerifyServiceSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.internal.signature');
        $provided = (string) $request->header('Signature', '');

        if (
            $expected === ''
            || $provided === ''
            || ! hash_equals($expected, $provided)
        ) {
            $error = (new HttpErrorResponseObjectOfResult)->setStatus(403)->setCode(403)->setMessage('Forbidden.');

            return response()->json($error->toArray(), 403);
        }

        return $next($request);
    }
}
