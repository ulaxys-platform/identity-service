<?php

namespace Ulaxys\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Ulaxys\Identity\Transformers\ErrorResponseObjects\HttpErrorResponseObjectOfResult;

final class ValidateJsonBody
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true) && $request->isJson() && $request->getContent() !== '') {
            try {
                $body = json_decode($request->getContent(), false, 512, JSON_THROW_ON_ERROR);
                if (! $body instanceof \stdClass) {
                    throw new \JsonException;
                }
            } catch (\JsonException) {
                $error = (new HttpErrorResponseObjectOfResult)->setStatus(400)->setCode(400)->setMessage('Request body must be a valid JSON object.');

                return response()->json($error->toArray(), 400);
            }
        }

        return $next($request);
    }
}
