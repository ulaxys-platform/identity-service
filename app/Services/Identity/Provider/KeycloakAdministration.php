<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Services\Identity\Provider;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

final class KeycloakAdministration
{
    public function issuer(): string
    {
        $base = config('keycloak.base_url');
        $realm = config('keycloak.realm');
        $issuer = config('keycloak.issuer');
        $uri = is_string($base) ? parse_url($base) : false;
        if (! is_array($uri) || ($uri['scheme'] ?? null) !== 'https' || empty($uri['host'])
            || isset($uri['user']) || isset($uri['pass']) || isset($uri['query']) || isset($uri['fragment'])
            || ! is_string($realm) || ! preg_match('/^[a-zA-Z0-9_-]+$/D', $realm)
            || ! is_string($issuer) || strlen($issuer) > 255 || rtrim($base, '/').'/realms/'.$realm !== $issuer
            || ! filter_var($issuer, FILTER_VALIDATE_URL)) {
            throw new ProviderFailure('The Keycloak issuer and HTTPS endpoint must be configured.');
        }

        return $issuer;
    }

    public function ensureDisabledAccount(string $userId, ?string $email = null): string
    {
        try {
            $admin = $this->authenticatedRequest();
            $url = $this->adminUrl().'/users';
            $username = 'identity-'.strtolower($userId);
            $account = $this->find($admin, $url, $username);
            if ($account === null) {
                $create = $admin->post($url, [
                    'username' => $username,
                    'enabled' => false,
                    ...($email === null ? [] : ['email' => $email, 'emailVerified' => false]),
                    'attributes' => ['identity_platform_ulid' => [$userId]],
                ]);
                if (! in_array($create->status(), [201, 409], true)) {
                    throw new ProviderFailure('Keycloak account creation failed.');
                }

                $account = $this->find($admin, $url, $username);
            }
        } catch (ConnectionException) {
            // A timed-out POST may have succeeded. Reconcile by correlation on the next run.
            throw new ProviderFailure('Keycloak is temporarily unavailable.');
        }

        $subject = $account['id'] ?? null;
        if (! is_string($subject) || $subject === '' || strlen($subject) > 255
            || ($account['username'] ?? null) !== $username
            || ($account['enabled'] ?? null) !== false
            || ($account['attributes']['identity_platform_ulid'] ?? null) !== [$userId]) {
            throw new ProviderFailure('The Keycloak account could not be safely correlated.');
        }

        return $subject;
    }

    public function verifyActivatedAccount(string $subject, string $userId, ?string $email): void
    {
        try {
            $account = $this->authenticatedRequest()->get($this->adminUrl().'/users/'.rawurlencode($subject));
        } catch (ConnectionException) {
            throw new ProviderFailure('Keycloak is temporarily unavailable.');
        }
        if (! $account->ok() || $account->json('id') !== $subject
            || $account->json('username') !== 'identity-'.strtolower($userId)
            || $account->json('attributes.identity_platform_ulid') !== [$userId]
            || ($email !== null && $account->json('email') !== $email)
            || $account->json('enabled') !== true || $account->json('emailVerified') !== true
            || $account->json('requiredActions') !== []) {
            throw new ProviderFailure('Keycloak account activation could not be confirmed.');
        }
    }

    public function inviteAccount(string $subject, string $userId, string $email): void
    {
        $actions = ['VERIFY_EMAIL', 'UPDATE_PASSWORD'];
        try {
            $admin = $this->authenticatedRequest();
            $endpoint = $this->adminUrl().'/users/'.rawurlencode($subject);
            $account = $admin->get($endpoint);
            if (! $account->ok() || $account->json('id') !== $subject
                || $account->json('username') !== 'identity-'.strtolower($userId)
                || $account->json('attributes.identity_platform_ulid') !== [$userId]
                || $account->json('email') !== $email) {
                throw new ProviderFailure('The account cannot be safely invited.');
            }
            if ($account->json('enabled') !== true
                && $admin->put($endpoint, ['enabled' => true, 'requiredActions' => $actions])->status() !== 204) {
                throw new ProviderFailure('Keycloak required actions could not be assigned.');
            }
            if ($admin->put($endpoint.'/execute-actions-email?lifespan=900', $actions)->status() !== 204) {
                throw new ProviderFailure('Keycloak did not acknowledge activation delivery.');
            }
        } catch (ConnectionException) {
            throw new ProviderFailure('Keycloak activation delivery was not confirmed.');
        }
    }

    public function disableAccount(string $subject, string $userId): void
    {
        try {
            $admin = $this->authenticatedRequest();
            $endpoint = $this->adminUrl().'/users/'.rawurlencode($subject);
            $account = $admin->get($endpoint);
            if (! $account->ok() || $account->json('id') !== $subject
                || $account->json('attributes.identity_platform_ulid') !== [$userId]
                || $account->json('username') !== 'identity-'.strtolower($userId)
                || $admin->put($endpoint, ['enabled' => false])->status() !== 204
                || $admin->post($endpoint.'/logout')->status() !== 204) {
                throw new ProviderFailure('Keycloak disablement is awaiting reconciliation.');
            }
        } catch (ConnectionException) {
            throw new ProviderFailure('Keycloak is temporarily unavailable.');
        }
    }

    public function revokeSession(string $session): void
    {
        try {
            $response = $this->authenticatedRequest()->delete($this->adminUrl().'/sessions/'.rawurlencode($session));
        } catch (ConnectionException) {
            throw new ProviderFailure('Keycloak is temporarily unavailable.');
        }
        if (! in_array($response->status(), [204, 404], true)) {
            throw new ProviderFailure('Keycloak did not revoke the session.');
        }
    }

    private function adminUrl(): string
    {
        return rtrim(config('keycloak.base_url'), '/').'/admin/realms/'.config('keycloak.realm');
    }

    private function authenticatedRequest(): PendingRequest
    {
        $issuer = $this->issuer();
        $clientId = config('keycloak.admin.client_id');
        $secret = config('keycloak.admin.client_secret');
        if (! is_string($clientId) || $clientId === '' || ! is_string($secret) || $secret === '') {
            throw new ProviderFailure('The Keycloak administration client must be configured.');
        }
        $response = $this->request()->asForm()->withBasicAuth($clientId, $secret)
            ->post($issuer.'/protocol/openid-connect/token', [
                'grant_type' => 'client_credentials',
                'scope' => 'kc-admin-api',
            ]);
        $token = $response->json('access_token');
        if (! $response->ok() || ! is_string($token) || $token === '') {
            throw new ProviderFailure('Keycloak administration authentication failed.');
        }

        return $this->request()->withToken($token);
    }

    private function find(PendingRequest $admin, string $url, string $username): ?array
    {
        $response = $admin->get($url, ['username' => $username, 'exact' => 'true', 'max' => 2, 'briefRepresentation' => 'false']);
        $matches = $response->json();
        if (! $response->ok() || ! is_array($matches) || ! array_is_list($matches)
            || count($matches) > 1 || (isset($matches[0]) && ! is_array($matches[0]))) {
            throw new ProviderFailure('Keycloak returned an invalid account lookup result.');
        }

        return $matches[0] ?? null;
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()->withoutRedirecting()
            ->connectTimeout(max(1, min(5, (int) config('keycloak.connect_timeout'))))
            ->timeout(max(1, min(15, (int) config('keycloak.request_timeout'))));
    }
}
