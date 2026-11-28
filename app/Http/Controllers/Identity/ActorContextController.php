<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Http\Controllers\Identity;

use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;
use Ulaxys\Identity\Http\Controllers\Controller;
use Ulaxys\Identity\Services\Identity\ActorContextService;
use Ulaxys\Identity\Transformers\Identity\ActorContextResponse;

final class ActorContextController extends Controller
{
    public function __construct(private ActorContextService $service) {}

    #[OA\Get(
        path: '/api/v1/actor/context',
        operationId: 'identity/actor/context',
        tags: ['IdentityActorContext'],
        security: [['serviceSignature' => [], 'bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Versioned actor context of the verified token', content: new OA\JsonContent(ref: '#/components/schemas/IdentityActorContextResponse')),
            new OA\Response(response: 401, description: 'Missing or invalid actor token', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
            new OA\Response(response: 403, description: 'Signature required', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
        ],
    )]
    public function show(): JsonResponse
    {
        $response = (new ActorContextResponse)->setStatus(200)->setMessage('OK')->setSize(1)
            ->withItem($this->service->forProviderActor(request()->attributes->get('actor.user'), request()->attributes->get('actor.claims')));

        return response()->json($response->toArray(), $response->getStatus());
    }
}
