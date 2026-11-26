<?php

namespace Ulaxys\Identity\Services\Gateway;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ServiceProxy
{
    public array $calls = [];

    public Response $response;

    public function __construct()
    {
        $this->response = new Response('opaque upstream response', 409, ['X-Upstream' => 'preserved']);
    }

    public function forward(string $service, Request $request, string $path): Response
    {
        $this->calls[] = compact('service', 'request', 'path');

        return $this->response;
    }
}
