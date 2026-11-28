<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Http\Controllers\Identity;

use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;
use Ulaxys\Identity\Http\Controllers\Controller;
use Ulaxys\Identity\Http\Requests\Identity\UpdateProfileRequest;
use Ulaxys\Identity\Presenters\Mapper\Identity\UserMapper;
use Ulaxys\Identity\Repositories\RepositoryInterface\UserRepositoryInterface;
use Ulaxys\Identity\Services\Identity\AuditLogger;
use Ulaxys\Identity\Transformers\Identity\UserProfileResponse;

final class ProfileController extends Controller
{
    public function __construct(
        private UserRepositoryInterface $users,
        private UserMapper $mapper,
        private AuditLogger $audit,
    ) {}

    #[OA\Get(
        path: '/api/v1/profile/me',
        operationId: 'identity/profile/show',
        tags: ['IdentityProfile'],
        security: [['serviceSignature' => [], 'bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Own profile', content: new OA\JsonContent(ref: '#/components/schemas/IdentityUserProfileResponse')),
            new OA\Response(response: 401, description: 'Missing or invalid actor token', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
            new OA\Response(response: 403, description: 'Signature required', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
        ],
    )]
    public function show(): JsonResponse
    {
        $user = request()->attributes->get('actor.user');
        $response = (new UserProfileResponse)->setStatus(200)->setMessage('OK')->setSize(1)->withItem($this->mapper->map($user));

        return response()->json($response->toArray(), $response->getStatus());
    }

    #[OA\Patch(
        path: '/api/v1/profile/me',
        operationId: 'identity/profile/update',
        tags: ['IdentityProfile'],
        security: [['serviceSignature' => [], 'bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/IdentityUpdateProfileInput')),
        responses: [
            new OA\Response(response: 200, description: 'Updated profile', content: new OA\JsonContent(ref: '#/components/schemas/IdentityUserProfileResponse')),
            new OA\Response(response: 400, description: 'Invalid body', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
            new OA\Response(response: 401, description: 'Missing or invalid actor token', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
            new OA\Response(response: 403, description: 'Signature required', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
        ],
    )]
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = request()->attributes->get('actor.user');
        $attributes = $request->validated();
        $columns = [];
        if (array_key_exists('displayName', $attributes)) {
            $columns['display_name'] = (string) $attributes['displayName'];
        }
        if (array_key_exists('firstName', $attributes)) {
            $columns['first_name'] = $attributes['firstName'];
        }
        if (array_key_exists('lastName', $attributes)) {
            $columns['last_name'] = $attributes['lastName'];
        }
        if (array_key_exists('preferredLanguage', $attributes)) {
            $columns['preferred_language'] = (string) $attributes['preferredLanguage'];
        }
        if (array_key_exists('preferredTimezone', $attributes)) {
            $columns['preferred_timezone'] = (string) $attributes['preferredTimezone'];
        }
        $this->users->updateProfile((string) $user->id, $columns);
        $this->audit->audit('identity.profile.updated', (string) $user->id, 'user', 'user', (string) $user->id, 'success', ['fields' => array_keys($columns)]);
        $fresh = $this->users->findById((string) $user->id) ?? $user;
        $response = (new UserProfileResponse)->setStatus(200)->setMessage('OK')->setSize(1)->withItem($this->mapper->map($fresh));

        return response()->json($response->toArray(), $response->getStatus());
    }
}
