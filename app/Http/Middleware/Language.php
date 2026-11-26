<?php

namespace Ulaxys\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Ulaxys\Identity\Transformers\ErrorResponseObjects\HttpErrorResponseObjectOfResult;

final class Language
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->header('Language', 'en');
        if (! is_string($locale) || strlen($locale) > 35 || preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/iD', $locale) !== 1) {
            $error = (new HttpErrorResponseObjectOfResult)->setStatus(400)->setCode(400)->setMessage('Language must be a valid language tag.');

            return response()->json($error->toArray(), 400);
        }
        app()->setLocale(strtolower($locale));

        return $next($request);
    }
}
