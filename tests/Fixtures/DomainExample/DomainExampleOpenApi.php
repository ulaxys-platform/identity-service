<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Tests\Fixtures\DomainExample;

use OpenApi\Attributes as OA;

/** Standalone scanner context; test fixtures are NEVER scanned by production L5-Swagger. */
#[OA\Info(title: 'Domain fixture only', version: '1.0.0')]
#[OA\Server(url: '/', description: 'Current Service')]
#[OA\SecurityScheme(securityScheme: 'serviceSignature', type: 'apiKey', in: 'header', name: 'Signature')]
final class DomainExampleOpenApi {}

#[OA\Schema(
    schema: 'ExampleLabelCreateInput',
    required: ['code', 'title'],
    additionalProperties: false,
    properties: [
        new OA\Property(property: 'code', type: 'string', pattern: '^[A-Z][A-Z0-9_]{1,19}$'),
        new OA\Property(property: 'title', type: 'object', required: ['en'], additionalProperties: false, properties: [
            new OA\Property(property: 'en', type: 'string', maxLength: 120),
            new OA\Property(property: 'tr', type: 'string', maxLength: 120),
            new OA\Property(property: 'ar', type: 'string', maxLength: 120),
        ]),
    ],
)]
final class ExampleLabelCreateInputSchema {}

#[OA\Schema(
    schema: 'ExampleLabelError',
    required: ['status', 'code', 'message'],
    properties: [
        new OA\Property(property: 'status', type: 'integer'),
        new OA\Property(property: 'code', type: 'integer'),
        new OA\Property(property: 'message', type: 'string'),
    ],
)]
final class ExampleLabelErrorSchema {}
