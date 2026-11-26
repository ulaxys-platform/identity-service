<?php

namespace Ulaxys\Identity\Http\Controllers;

use OpenApi\Attributes as OA;

#[OA\Info(version: '1.0.0', title: 'uLaxys Service API')]
#[OA\Server(url: '/', description: 'Current Service')]
#[OA\SecurityScheme(securityScheme: 'serviceSignature', type: 'apiKey', in: 'header', name: 'Signature')]
abstract class Controller extends \Illuminate\Routing\Controller {}
