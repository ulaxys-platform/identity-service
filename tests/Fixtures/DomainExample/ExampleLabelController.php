<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Tests\Fixtures\DomainExample;

use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;
use Ulaxys\Identity\Http\Controllers\Controller;

/**
 * Executable test-only route fixture. Attributes show operation-local documentation;
 * the production OpenAPI scanner intentionally excludes tests/Fixtures.
 */
final class ExampleLabelController extends Controller
{
    public function __construct(private ExampleLabelService $service) {}

    #[OA\Get(
        path: '/example/labels',
        operationId: 'example/labels/list',
        tags: ['ExampleLabel'],
        security: [['serviceSignature' => []]],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25)),
            new OA\Parameter(name: 'offset', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 0, default: 0)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paged labels', content: new OA\JsonContent(ref: '#/components/schemas/ExampleLabelListResponse')),
            new OA\Response(response: 400, description: 'Invalid query', content: new OA\JsonContent(ref: '#/components/schemas/ExampleLabelError')),
            new OA\Response(response: 403, description: 'Signature required', content: new OA\JsonContent(ref: '#/components/schemas/ExampleLabelError')),
        ],
    )]
    public function index(ListExampleLabelsRequest $request): JsonResponse
    {
        $response = $this->service->list($request->validated());

        return response()->json($response->toArray(), $response->getStatus());
    }

    #[OA\Post(
        path: '/example/labels',
        operationId: 'example/labels/create',
        tags: ['ExampleLabel'],
        security: [['serviceSignature' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ExampleLabelCreateInput')),
        responses: [
            new OA\Response(response: 201, description: 'Label created', content: new OA\JsonContent(ref: '#/components/schemas/ExampleLabelCreateResponse')),
            new OA\Response(response: 400, description: 'Invalid body', content: new OA\JsonContent(ref: '#/components/schemas/ExampleLabelError')),
            new OA\Response(response: 403, description: 'Signature required', content: new OA\JsonContent(ref: '#/components/schemas/ExampleLabelError')),
            new OA\Response(response: 409, description: 'Duplicate code', content: new OA\JsonContent(ref: '#/components/schemas/ExampleLabelError')),
        ],
    )]
    public function store(CreateExampleLabelRequest $request): JsonResponse
    {
        $response = $this->service->create($request->validated());

        return response()->json($response->toArray(), $response->getStatus());
    }
}
