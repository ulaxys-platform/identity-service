<?php

namespace Ulaxys\Identity\Http\Controllers\Documentation;

use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Ulaxys\Identity\Http\Controllers\Controller;
use Ulaxys\Identity\Transformers\ErrorResponseObjects\HttpErrorResponseObjectOfResult;

final class OpenApiController extends Controller
{
    public function __invoke(): BinaryFileResponse|Response
    {
        $documentation = config(
            'l5-swagger.default',
            'default'
        );

        $directory = config(
            'l5-swagger.defaults.paths.docs'
        );

        $filename = config(
            "l5-swagger.documentations.{$documentation}.paths.docs_json",
            'api-docs.json'
        );

        $path = rtrim(
            (string) $directory,
            DIRECTORY_SEPARATOR
        ).DIRECTORY_SEPARATOR.$filename;

        if (! is_file($path)) {
            $error = (new HttpErrorResponseObjectOfResult)
                ->setStatus(Response::HTTP_SERVICE_UNAVAILABLE)
                ->setCode(Response::HTTP_SERVICE_UNAVAILABLE)
                ->setMessage('OpenAPI document is not available.');

            return new Response($error->toArray(), Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return response()->file(
            $path,
            [
                'Content-Type' => 'application/json; charset=UTF-8',

                'Cache-Control' => 'no-store, private',

                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }
}
