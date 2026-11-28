<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Http\Controllers\Identity;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Ulaxys\Identity\Http\Controllers\Controller;
use Ulaxys\Identity\Services\Identity\SessionService;

final class SessionController extends Controller
{
    public function __construct(private SessionService $service) {}

    #[OA\Get(
        path: '/api/v1/sessions',
        operationId: 'identity/sessions/index',
        tags: ['IdentitySessions'],
        security: [['serviceSignature' => [], 'bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Own active sessions', content: new OA\JsonContent(ref: '#/components/schemas/IdentitySessionListResponse')),
            new OA\Response(response: 401, description: 'Missing or invalid actor token', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
            new OA\Response(response: 403, description: 'Signature required', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
        ],
    )]
    public function index(): JsonResponse
    {
        $response = $this->service->listForUser(request()->attributes->get('actor.user'), request()->attributes->get('actor.session'));

        return response()->json($response->toArray(), $response->getStatus());
    }

    #[OA\Delete(
        path: '/api/v1/sessions/current',
        operationId: 'identity/sessions/revoke-current',
        tags: ['IdentitySessions'],
        security: [['serviceSignature' => [], 'bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Current session revoked', content: new OA\JsonContent(ref: '#/components/schemas/HttpSuccessResponseObject')),
            new OA\Response(response: 401, description: 'Missing or invalid actor token', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
            new OA\Response(response: 403, description: 'Signature required', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
        ],
    )]
    public function revokeCurrent(): JsonResponse
    {
        $response = $this->service->revokeCurrent(request()->attributes->get('actor.user'), request()->attributes->get('actor.session'));

        return response()->json($response->toArray(), $response->getStatus());
    }

    #[OA\Delete(
        path: '/api/v1/sessions/all',
        operationId: 'identity/sessions/revoke-all',
        tags: ['IdentitySessions'],
        security: [['serviceSignature' => [], 'bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'All other sessions revoked', content: new OA\JsonContent(ref: '#/components/schemas/HttpSuccessResponseObject')),
            new OA\Response(response: 401, description: 'Missing or invalid actor token', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
            new OA\Response(response: 403, description: 'Signature required', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
        ],
    )]
    public function revokeAllOthers(): JsonResponse
    {
        $response = $this->service->revokeAllOthers(request()->attributes->get('actor.user'), request()->attributes->get('actor.session'));

        return response()->json($response->toArray(), $response->getStatus());
    }

    #[OA\Delete(
        path: '/api/v1/sessions/{sessionId}',
        operationId: 'identity/sessions/revoke',
        tags: ['IdentitySessions'],
        security: [['serviceSignature' => [], 'bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'sessionId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Session revoked', content: new OA\JsonContent(ref: '#/components/schemas/HttpSuccessResponseObject')),
            new OA\Response(response: 401, description: 'Missing or invalid actor token', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
            new OA\Response(response: 403, description: 'Signature required', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
            new OA\Response(response: 404, description: 'Session not found or not owned by the actor', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
        ],
    )]
    public function revoke(Request $request, string $sessionId): JsonResponse
    {
        $response = $this->service->revoke(request()->attributes->get('actor.user'), $sessionId);

        return response()->json($response->toArray(), $response->getStatus());
    }
}
