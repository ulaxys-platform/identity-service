<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Http\Controllers\Identity;

use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;
use Ulaxys\Identity\Http\Controllers\Controller;
use Ulaxys\Identity\Http\Requests\Identity\CreateAdminUserRequest;
use Ulaxys\Identity\Http\Requests\Identity\ListAdminUsersRequest;
use Ulaxys\Identity\Services\Identity\UserAdministrationService;

final class AdminUserController extends Controller
{
    public function __construct(private UserAdministrationService $service) {}

    #[OA\Get(
        path: '/api/v1/admin/users',
        operationId: 'identity/admin-users/index',
        tags: ['IdentityAdminUsers'],
        security: [['serviceSignature' => []], ['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100)),
            new OA\Parameter(name: 'offset', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 0)),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['invited', 'pending_verification', 'active', 'suspended', 'disabled', 'archived'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paged users', content: new OA\JsonContent(ref: '#/components/schemas/IdentityUserListResponse')),
            new OA\Response(response: 401, description: 'Missing or invalid actor token', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
            new OA\Response(response: 403, description: 'Signature required or platform administrator grant missing', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
        ],
    )]
    public function index(ListAdminUsersRequest $request): JsonResponse
    {
        $data = $request->validated();
        $response = $this->service->page(
            (int) ($data['limit'] ?? 25),
            (int) ($data['offset'] ?? 0),
            isset($data['status']) ? (string) $data['status'] : null,
        );

        return response()->json($response->toArray(), $response->getStatus());
    }

    #[OA\Post(
        path: '/api/v1/admin/users',
        operationId: 'identity/admin-users/create',
        tags: ['IdentityAdminUsers'],
        security: [['serviceSignature' => []], ['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/IdentityCreateUserInput')),
        responses: [
            new OA\Response(response: 201, description: 'Invited user created without credentials', content: new OA\JsonContent(ref: '#/components/schemas/IdentityUserCreatedResponse')),
            new OA\Response(response: 400, description: 'Invalid body', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
            new OA\Response(response: 401, description: 'Missing or invalid actor token', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
            new OA\Response(response: 403, description: 'Signature required or platform administrator grant missing', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
            new OA\Response(response: 409, description: 'Identifier already in use', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
        ],
    )]
    public function store(CreateAdminUserRequest $request): JsonResponse
    {
        $response = $this->service->create(request()->attributes->get('actor.user'), $request->validated());

        return response()->json($response->toArray(), $response->getStatus());
    }

    #[OA\Get(
        path: '/api/v1/admin/users/{userId}',
        operationId: 'identity/admin-users/show',
        tags: ['IdentityAdminUsers'],
        security: [['serviceSignature' => []], ['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'User detail', content: new OA\JsonContent(ref: '#/components/schemas/IdentityUserProfileResponse')),
            new OA\Response(response: 401, description: 'Missing or invalid actor token', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
            new OA\Response(response: 403, description: 'Signature required or platform administrator grant missing', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
            new OA\Response(response: 404, description: 'User not found', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
        ],
    )]
    public function show(string $userId): JsonResponse
    {
        $response = $this->service->show($userId);

        return response()->json($response->toArray(), $response->getStatus());
    }
}
