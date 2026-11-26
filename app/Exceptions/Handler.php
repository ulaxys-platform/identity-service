<?php

namespace Ulaxys\Identity\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;
use Ulaxys\Identity\Transformers\ErrorResponseObjects\HttpErrorResponseObjectOfResult;

class Handler extends ExceptionHandler
{
    protected $dontFlash = ['current_password', 'password', 'password_confirmation'];

    public function render($request, Throwable $e)
    {
        if ($e instanceof HttpResponseException) {
            return $e->getResponse();
        }
        [$status,$message] = match (true) {
            $e instanceof ValidationException => [400, implode(' ', array_merge(...array_values($e->errors())))],
            $e instanceof ModelNotFoundException => [404, 'Resource not found.'],
            $e instanceof AuthorizationException => [403, 'Forbidden.'],
            $e instanceof QueryException => match ($e->errorInfo[0] ?? null) {
                '23503','23505','23P01','40001','40P01','55P03','57014' => [409, 'Data conflict or busy transaction; reload and retry.'],
                '23514','22003','22P02','22007','22008' => [400, 'Request violates field constraints.'],
                default => [500, 'Internal server error.'],
            },
            $e instanceof HttpExceptionInterface => [$e->getStatusCode(), match ($e->getStatusCode()) {
                404 => 'Resource not found.',403 => 'Forbidden.',405 => 'Method not allowed.',429 => 'Too many requests.',default => 'Request failed.'
            }],
            default => [500, 'Internal server error.'],
        };
        $error = (new HttpErrorResponseObjectOfResult)->setStatus($status)->setCode($status)->setMessage($message);

        return response()->json($error->toArray(), $status, $e instanceof HttpExceptionInterface ? $e->getHeaders() : []);
    }
}
