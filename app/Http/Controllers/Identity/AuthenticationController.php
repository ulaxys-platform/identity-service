<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Http\Controllers\Identity;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;
use Ulaxys\Identity\Http\Controllers\Controller;
use Ulaxys\Identity\Http\Requests\Identity\AccountSetupRequest;
use Ulaxys\Identity\Http\Requests\Identity\LoginRequest;
use Ulaxys\Identity\Http\Requests\Identity\RefreshTokenRequest;
use Ulaxys\Identity\Services\Identity\AuthenticationService;

final class AuthenticationController extends Controller
{
    public function __construct(private AuthenticationService $service) {}

    #[OA\Post(
        path: '/api/v1/auth/login',
        operationId: 'identity/auth/login',
        tags: ['IdentityAuthentication'],
        security: [['serviceSignature' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/IdentityLoginInput')),
        responses: [
            new OA\Response(response: 200, description: 'Authenticated session with tokens', content: new OA\JsonContent(ref: '#/components/schemas/IdentityLoginResponse')),
            new OA\Response(response: 400, description: 'Invalid body', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
            new OA\Response(response: 401, description: 'Invalid credentials', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
            new OA\Response(response: 403, description: 'Signature required', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
            new OA\Response(response: 429, description: 'Rate limited', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
            new OA\Response(response: 503, description: 'Signing keys unavailable', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
        ],
    )]
    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();
        $response = $this->service->login(
            (string) $data['identifier'],
            (string) $data['password'],
            isset($data['deviceLabel']) ? (string) $data['deviceLabel'] : null,
            $request->ip(),
            (string) $request->userAgent(),
            Str::limit((string) $request->header('X-Correlation-Id', ''), 64, ''),
        );

        return response()->json($response->toArray(), $response->getStatus());
    }

    #[OA\Post(
        path: '/api/v1/auth/refresh',
        operationId: 'identity/auth/refresh',
        tags: ['IdentityAuthentication'],
        security: [['serviceSignature' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/IdentityRefreshInput')),
        responses: [
            new OA\Response(response: 200, description: 'Rotated token grant', content: new OA\JsonContent(ref: '#/components/schemas/IdentityTokenRefreshResponse')),
            new OA\Response(response: 400, description: 'Invalid body', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
            new OA\Response(response: 401, description: 'Invalid, expired or replayed refresh token', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
            new OA\Response(response: 403, description: 'Signature required', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
            new OA\Response(response: 429, description: 'Rate limited', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
            new OA\Response(response: 503, description: 'Signing keys unavailable', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
        ],
    )]
    public function refresh(RefreshTokenRequest $request): JsonResponse
    {
        $response = $this->service->refresh((string) $request->validated()['refreshToken'], $request->ip());

        return response()->json($response->toArray(), $response->getStatus());
    }

    #[OA\Post(
        path: '/api/v1/auth/setup',
        operationId: 'identity/auth/setup',
        tags: ['IdentityAuthentication'],
        security: [['serviceSignature' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/IdentityAccountSetupInput')),
        responses: [
            new OA\Response(response: 201, description: 'Account activated with initial password', content: new OA\JsonContent(ref: '#/components/schemas/HttpSuccessResponseObject')),
            new OA\Response(response: 400, description: 'Invalid, expired or consumed setup token', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
            new OA\Response(response: 403, description: 'Signature required', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
            new OA\Response(response: 429, description: 'Rate limited', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
        ],
    )]
    public function setup(AccountSetupRequest $request): JsonResponse
    {
        $data = $request->validated();
        $response = $this->service->setup((string) $data['token'], (string) $data['newPassword']);

        return response()->json($response->toArray(), $response->getStatus());
    }

    #[OA\Post(
        path: '/api/v1/auth/logout',
        operationId: 'identity/auth/logout',
        tags: ['IdentityAuthentication'],
        security: [['serviceSignature' => [], 'bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Session revoked', content: new OA\JsonContent(ref: '#/components/schemas/HttpSuccessResponseObject')),
            new OA\Response(response: 401, description: 'Missing or invalid actor token', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
            new OA\Response(response: 403, description: 'Signature required', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
        ],
    )]
    public function logout(): JsonResponse
    {
        $response = $this->service->logout(request()->attributes->get('actor.user'), request()->attributes->get('actor.session'));

        return response()->json($response->toArray(), $response->getStatus());
    }
}
