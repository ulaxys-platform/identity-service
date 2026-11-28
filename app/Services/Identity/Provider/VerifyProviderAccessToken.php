<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Services\Identity\Provider;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use UnexpectedValueException;

final class VerifyProviderAccessToken
{
    public function __construct(private readonly KeycloakAdministration $provider) {}

    public function verify(#[\SensitiveParameter] string $token): ?array
    {
        $audience = config('keycloak.access.audience');
        $client = config('keycloak.access.introspection_client_id');
        $secret = config('keycloak.access.introspection_client_secret');
        if (! is_string($audience) || $audience === '' || ! is_string($client) || $client === ''
            || ! is_string($secret) || $secret === '' || $client === config('keycloak.admin.client_id')) {
            throw new ProviderFailure('Keycloak audience and a separate introspection client are required.');
        }

        $issuer = $this->provider->issuer();
        $cacheKey = 'identity:keycloak:'.hash('sha256', $issuer);
        if ($token === '' || strlen($token) > 16384) {
            return null;
        }

        try {
            $keys = Cache::remember($cacheKey, 120, fn () => $this->readKeys($issuer));
            try {
                $claims = (array) JWT::decode($token, $this->parseKeys($keys));
            } catch (UnexpectedValueException $exception) {
                if ($exception->getMessage() !== '"kid" invalid, unable to lookup correct key'
                    || ! Cache::add($cacheKey.':refresh', true, 15)) {
                    return null;
                }

                $keys = $this->readKeys($issuer);
                Cache::put($cacheKey, $keys, 120);
                $claims = (array) JWT::decode($token, $this->parseKeys($keys));
            }

            $audiences = $claims['aud'] ?? null;
            if (is_string($audiences)) {
                $audiences = [$audiences];
            }
            if (($claims['iss'] ?? null) !== $issuer || ($claims['typ'] ?? null) !== 'Bearer'
                || ! is_array($audiences) || ! array_is_list($audiences) || ! in_array($audience, $audiences, true)
                || ! is_int($claims['exp'] ?? null) || ! is_int($claims['iat'] ?? null)
                || $claims['iat'] > time() || $claims['exp'] <= $claims['iat']
                || (array_key_exists('nbf', $claims) && ! is_int($claims['nbf']))
                || ! is_string($claims['sub'] ?? null) || $claims['sub'] === '' || strlen($claims['sub']) > 255
                || ! is_string($claims['sid'] ?? null) || preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $claims['sid']) !== 1) {
                return null;
            }

            $endpoint = $issuer.'/protocol/openid-connect/token/introspect';
            $response = $this->request()->withBasicAuth($client, $secret)->asForm()
                ->post($endpoint, ['token' => $token, 'token_type_hint' => 'access_token']);
            if (! $response->ok() || ! is_bool($response->json('active'))) {
                throw new ProviderFailure('Keycloak introspection is unavailable.');
            }

            return $response->json('active') === true
                && $response->json('iss') === $issuer
                && $response->json('sub') === $claims['sub']
                && $response->json('sid') === $claims['sid'] ? $claims : null;
        } catch (UnexpectedValueException|InvalidArgumentException) {
            return null;
        } catch (ConnectionException) {
            throw new ProviderFailure('Keycloak is temporarily unavailable.');
        }
    }

    private function readKeys(string $issuer): array
    {
        $discovery = $this->request()->get($issuer.'/.well-known/openid-configuration');
        $jwks = $issuer.'/protocol/openid-connect/certs';
        if (! $discovery->ok() || $discovery->json('issuer') !== $issuer
            || $discovery->json('jwks_uri') !== $jwks
            || $discovery->json('introspection_endpoint') !== $issuer.'/protocol/openid-connect/token/introspect') {
            throw new ProviderFailure('Untrusted Keycloak discovery document.');
        }

        $response = $this->request()->get($jwks);
        $keys = $response->json('keys');
        if (! $response->ok() || ! is_array($keys) || ! array_is_list($keys) || count($keys) > 64) {
            throw new ProviderFailure('Invalid Keycloak key set.');
        }
        $this->parseKeys($keys);

        return $keys;
    }

    private function parseKeys(array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            if (! is_array($key) || ($key['kty'] ?? null) !== 'RSA'
                || ($key['use'] ?? null) !== 'sig' || ($key['alg'] ?? null) !== 'RS256') {
                continue;
            }

            $id = $key['kid'] ?? null;
            if (! is_string($id) || $id === '' || isset($result[$id])) {
                throw new ProviderFailure('Invalid Keycloak signing key identifier.');
            }
            try {
                $result[$id] = JWK::parseKey($key, 'RS256');
            } catch (UnexpectedValueException|InvalidArgumentException) {
                throw new ProviderFailure('Invalid Keycloak signing key.');
            }
        }
        if ($result === []) {
            throw new ProviderFailure('Keycloak has no allowed signing key.');
        }

        return $result;
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()->withoutRedirecting()
            ->connectTimeout(max(1, min(5, (int) config('keycloak.connect_timeout'))))
            ->timeout(max(1, min(15, (int) config('keycloak.request_timeout'))));
    }
}
