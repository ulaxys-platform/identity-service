<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Http\Controllers\Identity;

use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;
use Ulaxys\Identity\Http\Controllers\Controller;
use Ulaxys\Identity\Http\Requests\Identity\ChangePasswordRequest;
use Ulaxys\Identity\Http\Requests\Identity\ConfirmEmailVerificationRequest;
use Ulaxys\Identity\Http\Requests\Identity\ForgotPasswordRequest;
use Ulaxys\Identity\Http\Requests\Identity\ResetPasswordRequest;
use Ulaxys\Identity\Services\Identity\CredentialService;

final class CredentialController extends Controller
{
    public function __construct(private CredentialService $service) {}

    #[OA\Post(
        path: '/api/v1/credentials/password',
        operationId: 'identity/credentials/change-password',
        tags: ['IdentityCredentials'],
        security: [['serviceSignature' => [], 'bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/IdentityChangePasswordInput')),
        responses: [
            new OA\Response(response: 200, description: 'Password changed; other sessions revoked', content: new OA\JsonContent(ref: '#/components/schemas/HttpSuccessResponseObject')),
            new OA\Response(response: 400, description: 'Wrong current password or policy violation', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
            new OA\Response(response: 401, description: 'Missing or invalid actor token', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
            new OA\Response(response: 403, description: 'Signature required', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
        ],
    )]
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $data = $request->validated();
        $response = $this->service->changePassword(
            request()->attributes->get('actor.user'),
            request()->attributes->get('actor.session'),
            (string) $data['currentPassword'],
            (string) $data['newPassword'],
        );

        return response()->json($response->toArray(), $response->getStatus());
    }

    #[OA\Post(
        path: '/api/v1/credentials/forgot-password',
        operationId: 'identity/credentials/forgot-password',
        tags: ['IdentityCredentials'],
        security: [['serviceSignature' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/IdentityForgotPasswordInput')),
        responses: [
            new OA\Response(response: 200, description: 'Always identical response regardless of account existence', content: new OA\JsonContent(ref: '#/components/schemas/HttpSuccessResponseObject')),
            new OA\Response(response: 400, description: 'Invalid body', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
            new OA\Response(response: 403, description: 'Signature required', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
            new OA\Response(response: 429, description: 'Rate limited', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
        ],
    )]
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $response = $this->service->forgotPassword((string) $request->validated()['identifier'], $request->ip());

        return response()->json($response->toArray(), $response->getStatus());
    }

    #[OA\Post(
        path: '/api/v1/credentials/reset-password',
        operationId: 'identity/credentials/reset-password',
        tags: ['IdentityCredentials'],
        security: [['serviceSignature' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/IdentityResetPasswordInput')),
        responses: [
            new OA\Response(response: 200, description: 'Password reset; all sessions revoked', content: new OA\JsonContent(ref: '#/components/schemas/HttpSuccessResponseObject')),
            new OA\Response(response: 400, description: 'Invalid, expired or consumed token; or policy violation', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
            new OA\Response(response: 403, description: 'Signature required', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
            new OA\Response(response: 429, description: 'Rate limited', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
        ],
    )]
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $data = $request->validated();
        $response = $this->service->resetPassword((string) $data['token'], (string) $data['newPassword']);

        return response()->json($response->toArray(), $response->getStatus());
    }

    #[OA\Post(
        path: '/api/v1/credentials/email-verification/start',
        operationId: 'identity/credentials/email-verification/start',
        tags: ['IdentityCredentials'],
        security: [['serviceSignature' => [], 'bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Verification challenge delivered through the token transport abstraction', content: new OA\JsonContent(ref: '#/components/schemas/HttpSuccessResponseObject')),
            new OA\Response(response: 401, description: 'Missing or invalid actor token', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
            new OA\Response(response: 403, description: 'Signature required', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
        ],
    )]
    public function startEmailVerification(): JsonResponse
    {
        $response = $this->service->startEmailVerification(request()->attributes->get('actor.user'));

        return response()->json($response->toArray(), $response->getStatus());
    }

    #[OA\Post(
        path: '/api/v1/credentials/email-verification/confirm',
        operationId: 'identity/credentials/email-verification/confirm',
        tags: ['IdentityCredentials'],
        security: [['serviceSignature' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/IdentityConfirmEmailVerificationInput')),
        responses: [
            new OA\Response(response: 200, description: 'Email identifier verified', content: new OA\JsonContent(ref: '#/components/schemas/HttpSuccessResponseObject')),
            new OA\Response(response: 400, description: 'Invalid, expired or consumed token', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
            new OA\Response(response: 403, description: 'Signature required', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
            new OA\Response(response: 429, description: 'Rate limited', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
        ],
    )]
    public function confirmEmailVerification(ConfirmEmailVerificationRequest $request): JsonResponse
    {
        $response = $this->service->confirmEmailVerification((string) $request->validated()['token']);

        return response()->json($response->toArray(), $response->getStatus());
    }
}
