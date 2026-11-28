<?php

namespace Ulaxys\Identity\Http\Controllers;

use OpenApi\Attributes as OA;

#[OA\Info(version: '1.0.0', title: 'uLaxys Service API')]
#[OA\Server(url: '/', description: 'Current Service')]
#[OA\SecurityScheme(securityScheme: 'serviceSignature', type: 'apiKey', in: 'header', name: 'Signature')]
#[OA\SecurityScheme(securityScheme: 'bearerAuth', type: 'http', scheme: 'bearer', bearerFormat: 'JWT', description: 'Identity RS256 access token issued by this service')]
abstract class Controller extends \Illuminate\Routing\Controller {}
