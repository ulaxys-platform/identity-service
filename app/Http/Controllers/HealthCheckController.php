<?php

namespace Ulaxys\Identity\Http\Controllers;

use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;
use Ulaxys\Identity\Http\Requests\HealthRequests\HealthStatusRequest;
use Ulaxys\Identity\Http\Requests\HealthRequests\PostgresStatusRequest;
use Ulaxys\Identity\Services\HealthService;

final class HealthCheckController extends Controller
{
    public function __construct(private HealthService $service) {}

    #[OA\Get(path: '/healthCheck/apiStatus', operationId: 'healthCheck/apiStatus', tags: ['HealthCheck'], responses: [new OA\Response(response: 200, description: 'Service is available', content: new OA\JsonContent(ref: '#/components/schemas/HttpSuccessResponseObject')), new OA\Response(response: 400, description: 'Invalid request', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult'))])]
    public function getHealthCheck(HealthStatusRequest $request): JsonResponse
    {
        $result = $this->service->getHealthCheck();

        return response()->json($result->toArray(), $result->getStatus());
    }

    #[OA\Get(path: '/healthCheck/postgresStatus', operationId: 'healthCheck/postgresStatus', tags: ['HealthCheck'], responses: [new OA\Response(response: 200, description: 'Database is available', content: new OA\JsonContent(ref: '#/components/schemas/HttpSuccessResponseObject')), new OA\Response(response: 503, description: 'Database is unavailable', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult'))])]
    public function getPostgresCheck(PostgresStatusRequest $request): JsonResponse
    {
        $result = $this->service->getPostgresCheck();

        return response()->json($result->toArray(), $result->getStatus());
    }
}
