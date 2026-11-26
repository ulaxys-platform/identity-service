<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Http\Controllers\Identity;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;
use Ulaxys\Identity\Http\Controllers\Controller;
use Ulaxys\Identity\Http\Requests\Identity\IntrospectTokenRequest;
use Ulaxys\Identity\Http\Requests\Identity\RevokeTokenRequest;
use Ulaxys\Identity\Repositories\RepositoryInterface\AuthSessionRepositoryInterface;
use Ulaxys\Identity\Repositories\RepositoryInterface\RefreshTokenRepositoryInterface;
use Ulaxys\Identity\Repositories\RepositoryInterface\UserRepositoryInterface;
use Ulaxys\Identity\Services\Identity\AuditLogger;
use Ulaxys\Identity\Services\Identity\Tokens\Jwk;
use Ulaxys\Identity\Services\Identity\Tokens\SigningKeyService;
use Ulaxys\Identity\Services\Identity\Tokens\TokenException;
use Ulaxys\Identity\Dto\Identity\JwkSetDto;
use Ulaxys\Identity\Dto\Identity\TokenIntrospectionDto;
use Ulaxys\Identity\Transformers\HttpSuccessResponseObject;
use Ulaxys\Identity\Transformers\Identity\IntrospectionResponse;
use Ulaxys\Identity\Transformers\Identity\JwkSetResponse;
use Ulaxys\Identity\Services\Identity\Tokens\JwtCodec;

/**
 * Internal Gateway token-validation contract endpoints (RFC 7662 / RFC 7009
 * semantics, RFC 7517 JWKS). They are NOT public OAuth/OIDC protocol
 * endpoints; full protocol support is deferred to the I2 engine decision
 * (ADR-0001). Every route is Signature-gated and machine-to-machine.
 */
final class OAuthContractController extends Controller
{
    public function __construct(
        private JwtCodec $codec,
        private SigningKeyService $signingKeys,
        private AuthSessionRepositoryInterface $sessions,
        private RefreshTokenRepositoryInterface $refreshTokens,
        private AuditLogger $audit,
        private UserRepositoryInterface $users,
    ) {}

    #[OA\Get(
        path: '/api/v1/oauth/jwks',
        operationId: 'identity/oauth/jwks',
        tags: ['IdentityGatewayContract'],
        security: [['serviceSignature' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Public signing keys (never private material)', content: new OA\JsonContent(ref: '#/components/schemas/IdentityJwkSetResponse')),
            new OA\Response(response: 403, description: 'Signature required', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
            new OA\Response(response: 503, description: 'Signing keys unavailable', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
        ],
    )]
    public function jwks(): JsonResponse
    {
        try {
            $keys = array_map(fn (string $pem) => Jwk::fromPublicKeyPem($pem), array_values($this->signingKeys->verificationKeys()));
        } catch (TokenException) {
            return response()->json(['status' => 503, 'code' => 503, 'message' => 'Authentication dependency unavailable.'], 503);
        }
        $response = (new JwkSetResponse)->setStatus(200)->setMessage('OK')->setSize(count($keys))->withItem(new JwkSetDto($keys));

        return response()->json($response->toArray(), $response->getStatus());
    }

    #[OA\Post(
        path: '/api/v1/oauth/introspect',
        operationId: 'identity/oauth/introspect',
        tags: ['IdentityGatewayContract'],
        security: [['serviceSignature' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/IdentityIntrospectInput')),
        responses: [
            new OA\Response(response: 200, description: 'RFC 7662-shaped introspection result; invalid tokens report active=false', content: new OA\JsonContent(ref: '#/components/schemas/IdentityIntrospectionResponse')),
            new OA\Response(response: 400, description: 'Invalid body', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
            new OA\Response(response: 403, description: 'Signature required', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
            new OA\Response(response: 429, description: 'Rate limited', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
            new OA\Response(response: 503, description: 'Signing keys unavailable', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
        ],
    )]
    public function introspect(IntrospectTokenRequest $request): JsonResponse
    {
        $token = (string) $request->validated()['token'];
        try {
            $claims = $this->codec->decode($token, $this->signingKeys->verificationKeys(), (string) config('identity.token.audience'));
        } catch (TokenException $exception) {
            if ($exception->reason === TokenException::KEYS_UNAVAILABLE) {
                return response()->json(['status' => 503, 'code' => 503, 'message' => 'Authentication dependency unavailable.'], 503);
            }

            return $this->introspectionResult(false);
        }
        $sid = is_string($claims['sid'] ?? null) ? (string) $claims['sid'] : null;
        $sub = is_string($claims['sub'] ?? null) ? (string) $claims['sub'] : null;
        $session = $sid === null ? null : $this->sessions->findById($sid);
        $user = $sub === null ? null : $this->users->findById($sub);
        if ($session === null || $user === null || (string) $session->user_id !== $sub || ! $user->isLoginEligible() || ! $session->isUsable()) {
            return $this->introspectionResult(false);
        }
        $introspection = new TokenIntrospectionDto(
            active: true,
            sub: $sub,
            sid: $sid,
            amr: is_array($claims['amr'] ?? null) ? $claims['amr'] : null,
            aud: is_string($claims['aud'] ?? null) ? (string) $claims['aud'] : null,
            iss: is_string($claims['iss'] ?? null) ? (string) $claims['iss'] : null,
            exp: is_int($claims['exp'] ?? null) ? $claims['exp'] : null,
            iat: is_int($claims['iat'] ?? null) ? $claims['iat'] : null,
            nbf: is_int($claims['nbf'] ?? null) ? $claims['nbf'] : null,
            jti: is_string($claims['jti'] ?? null) ? (string) $claims['jti'] : null,
            tokenType: 'access',
        );

        return $this->introspectionResult($introspection);
    }

    #[OA\Post(
        path: '/api/v1/oauth/revoke',
        operationId: 'identity/oauth/revoke',
        tags: ['IdentityGatewayContract'],
        security: [['serviceSignature' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/IdentityRevokeInput')),
        responses: [
            new OA\Response(response: 200, description: 'RFC 7009 semantics: always 200, even for unknown tokens', content: new OA\JsonContent(ref: '#/components/schemas/HttpSuccessResponseObject')),
            new OA\Response(response: 400, description: 'Invalid body', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
            new OA\Response(response: 403, description: 'Signature required', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
            new OA\Response(response: 503, description: 'Signing keys unavailable', content: new OA\JsonContent(ref: '#/components/schemas/HttpErrorResponseObjectOfResult')),
        ],
    )]
    public function revoke(RevokeTokenRequest $request): JsonResponse
    {
        $token = (string) $request->validated()['token'];
        try {
            $claims = $this->codec->decode($token, $this->signingKeys->verificationKeys(), (string) config('identity.token.audience'));
        } catch (TokenException $exception) {
            if ($exception->reason === TokenException::KEYS_UNAVAILABLE) {
                return response()->json(['status' => 503, 'code' => 503, 'message' => 'Authentication dependency unavailable.'], 503);
            }
            // RFC 7009: unknown or invalid tokens still yield a 200 response.
            return response()->json((new HttpSuccessResponseObject)->setStatus(200)->setMessage('Token revoked.')->toArray(), 200);
        }
        $sid = is_string($claims['sid'] ?? null) ? (string) $claims['sid'] : null;
        $sub = is_string($claims['sub'] ?? null) ? (string) $claims['sub'] : null;
        if ($sid !== null && $sub !== null) {
            DB::transaction(function () use ($sid, $sub): void {
                $session = $this->sessions->lockById($sid);
                if ($session === null || (string) $session->user_id !== $sub) {
                    return;
                }
                $this->sessions->revoke($sid, 'revoked_via_contract_endpoint');
                $this->refreshTokens->revokeForSession($sid, 'revoked_via_contract_endpoint');
                $this->audit->security('auth.session.revoked', $sub, $sid, 'success', ['reason' => 'gateway_revocation']);
            });
        }

        return response()->json((new HttpSuccessResponseObject)->setStatus(200)->setMessage('Token revoked.')->toArray(), 200);
    }

    private function introspectionResult(TokenIntrospectionDto|bool $result): JsonResponse
    {
        $dto = $result instanceof TokenIntrospectionDto ? $result : new TokenIntrospectionDto(active: false);
        $response = (new IntrospectionResponse)->setStatus(200)->setMessage('OK')->setSize(1)->withItem($dto);

        return response()->json($response->toArray(), $response->getStatus());
    }
}
