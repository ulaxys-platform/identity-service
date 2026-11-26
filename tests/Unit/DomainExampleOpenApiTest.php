<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Tests\Unit;

use OpenApi\Generator;
use PHPUnit\Framework\TestCase;

final class DomainExampleOpenApiTest extends TestCase
{
    public function test_fixture_operations_have_complete_request_response_and_signature_contracts(): void
    {
        $document = (new Generator)->setVersion('3.0.0')
            ->generate([dirname(__DIR__).'/Fixtures/DomainExample']);
        self::assertTrue($document->validate());

        $spec = json_decode($document->toJson(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Signature', $spec['components']['securitySchemes']['serviceSignature']['name']);

        $get = $spec['paths']['/example/labels']['get'];
        $post = $spec['paths']['/example/labels']['post'];
        self::assertSame('example/labels/list', $get['operationId']);
        self::assertSame('example/labels/create', $post['operationId']);

        foreach ([$get, $post] as $operation) {
            self::assertContains(['serviceSignature' => []], $operation['security']);
            self::assertArrayHasKey('403', $operation['responses']);
        }

        self::assertSame('#/components/schemas/ExampleLabelCreateInput', $post['requestBody']['content']['application/json']['schema']['$ref']);
        self::assertSame('#/components/schemas/ExampleLabelCreateResponse', $post['responses']['201']['content']['application/json']['schema']['$ref']);
        self::assertSame('#/components/schemas/ExampleLabelListResponse', $get['responses']['200']['content']['application/json']['schema']['$ref']);
        self::assertArrayHasKey('409', $post['responses']);
        self::assertSame(['code', 'title'], $spec['components']['schemas']['ExampleLabelCreateInput']['required']);
        self::assertFalse($spec['components']['schemas']['ExampleLabelDto']['additionalProperties']);
        self::assertSame('#/components/schemas/ExampleLabelDto', $spec['components']['schemas']['ExampleLabelCreateResponse']['properties']['items']['$ref']);
        self::assertSame('#/components/schemas/ExampleLabelDto', $spec['components']['schemas']['ExampleLabelListResponse']['properties']['items']['items']['$ref']);
        self::assertSame(['size', 'status', 'message', 'items', 'pagination'], $spec['components']['schemas']['ExampleLabelListResponse']['required']);
    }
}
