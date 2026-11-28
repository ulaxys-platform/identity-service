<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Http\Controllers\Identity;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Ulaxys\Identity\Http\Controllers\Controller;
use Ulaxys\Identity\Services\Identity\AuditLogger;
use Ulaxys\Identity\Services\Identity\Provider\KeycloakAdministration;
use Ulaxys\Identity\Services\Identity\Provider\ProviderFailure;

final class AuthenticationController extends Controller
{
    #[OA\Post(
        path: '/api/v1/auth/logout',
        operationId: 'identity/auth/logout',
        security: [['serviceSignature' => [], 'bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Current Keycloak session revoked'),
            new OA\Response(response: 401, description: 'Authentication required'),
            new OA\Response(response: 403, description: 'Service signature required'),
            new OA\Response(response: 503, description: 'Authentication dependency unavailable'),
        ],
    )]
    public function logout(Request $request, KeycloakAdministration $provider, AuditLogger $audit): JsonResponse
    {
        try {
            $provider->revokeSession($request->attributes->get('actor.claims')['sid']);
        } catch (ProviderFailure) {
            return response()->json(['status' => 503, 'code' => 503, 'message' => 'Authentication dependency unavailable.'], 503);
        }
        $user = $request->attributes->get('actor.user');
        $audit->audit('identity.provider.logout', (string) $user->id, 'user', 'user', (string) $user->id, 'success');

        return response()->json(['size' => 0, 'status' => 200, 'message' => 'Session revoked.']);
    }
}
