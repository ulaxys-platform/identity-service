<?php

declare(strict_types=1);

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

function expect(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function clientToken(array $discovery, string $client, string $secret, array $scopes = []): string
{
    $token = Http::timeout(10)->asForm()->withBasicAuth($client, $secret)
        ->post($discovery['token_endpoint'], ['grant_type' => 'client_credentials', ...$scopes])
        ->throw()->json('access_token');
    expect(is_string($token) && $token !== '', "Missing access token for $client");

    return $token;
}

function checkRealm(): void
{
    $base = 'http://identity-cloud-keycloak:8080';
    $realm = getenv('R0_REALM');
    $issuer = "$base/realms/$realm";
    $discovery = Http::timeout(10)->get("$issuer/.well-known/openid-configuration")->throw()->json();
    expect($discovery['issuer'] === $issuer, 'OIDC issuer mismatch');
    expect(in_array('S256', $discovery['code_challenge_methods_supported'], true), 'S256 unavailable');
    expect(count(Http::timeout(10)->get($discovery['jwks_uri'])->throw()->json('keys')) > 0, 'JWKS empty');
    echo "PASS discovery, S256 metadata, JWKS\n";

    $adminToken = clientToken($discovery, 'poc-provisioner', getenv('R0_ADMIN_SECRET'), ['scope' => 'kc-admin-api']);
    $machineToken = clientToken($discovery, 'poc-machine', getenv('R0_MACHINE_SECRET'));
    $admin = Http::timeout(10)->withToken($adminToken);
    $adminUrl = "$base/admin/realms/$realm";
    $machineAdminStatus = Http::timeout(10)->withToken($machineToken)->get("$adminUrl/users")->status();
    expect(in_array($machineAdminStatus, [401, 403], true), "Machine administrative response HTTP $machineAdminStatus");
    $provisionerClientsStatus = $admin->get("$adminUrl/clients")->status();
    expect($provisionerClientsStatus === 403, "Provisioner client administration response HTTP $provisionerClientsStatus");
    echo "PASS client credentials and scoped administration\n";

    $table = str_replace('-', '_', $realm).'_mappings';
    expect(preg_match('/^identity_r0_[a-f0-9]{12}_mappings$/', $table) === 1, 'Invalid fixture table name');
    expect(DB::connection()->getDatabaseName() === 'identity'
        && config('database.connections.pgsql.host') === 'identity-cloud-db', 'Unexpected database target');
    DB::statement("CREATE TABLE $table (
            issuer text NOT NULL,
            subject text NOT NULL,
            ulid char(26) NOT NULL UNIQUE,
            PRIMARY KEY (issuer, subject)
        )");
    try {

        require '/app/scripts/identity-r0/Provisioner.php';
        $provisioner = new Provisioner($admin, $adminUrl, $issuer, $table);
        $provision = static fn () => $provisioner->provision(getenv('R0_USER_PASSWORD'));

        DB::beginTransaction();
        try {
            $interrupted = $provision();
        } finally {
            DB::rollBack();
        }
        DB::purge();
        expect(DB::table($table)->count() === 0, 'The interrupted mapping transaction was not rolled back');
        $first = DB::transaction($provision);
        expect($first->subject === $interrupted->subject, 'Reconciliation created a second provider identity');
        DB::purge();
        $second = DB::transaction($provision);
        expect($first->ulid === $second->ulid && $first->subject === $second->subject, 'Mapping changed on retry');
        expect(DB::table($table)->count() === 1, 'Retry created a duplicate platform identity');
        echo "PASS Laravel Admin API provisioning, rollback reconciliation, and immutable mapping after database reconnect\n";

        $tokens = checkBrowser($discovery, $issuer, $first->subject);
        $browserApi = Http::timeout(10)->asForm()->withBasicAuth('poc-browser', getenv('R0_BROWSER_SECRET'));
        $introspect = static fn (string $token) => $browserApi->post($discovery['introspection_endpoint'], [
            'token' => $token, 'token_type_hint' => 'access_token',
        ])->throw()->json('active');

        expect($introspect($tokens['access_token']) === true, 'New access token is not active');
        $admin->post("$adminUrl/users/$first->subject/logout")->throw();
        expect($introspect($tokens['access_token']) === false, 'Access token remains active after session logout');
        echo "PASS authoritative introspection before and after administrative logout\n";

        $revoked = checkBrowser($discovery, $issuer, $first->subject);
        $refreshToken = static fn (string $token) => $browserApi->post($discovery['token_endpoint'], [
            'grant_type' => 'refresh_token', 'refresh_token' => $token,
        ]);
        $oldRefresh = $revoked['refresh_token'];
        $newRefresh = $refreshToken($oldRefresh)->throw()->json('refresh_token');
        expect(is_string($newRefresh) && $newRefresh !== $oldRefresh, 'Refresh token was not rotated');
        $revoked['refresh_token'] = $newRefresh;
        echo "PASS refresh grant and token rotation\n";
        $browserApi->post($discovery['revocation_endpoint'], [
            'token' => $revoked['refresh_token'], 'token_type_hint' => 'refresh_token',
        ])->throw();
        $refresh = $refreshToken($revoked['refresh_token']);
        expect($refresh->status() === 400 && $refresh->json('error') === 'invalid_grant',
            'Revoked refresh token remains usable');
        echo "PASS refresh token revocation\n";

        $oldKey = signingKeyId($revoked['id_token']);
        $keyToken = clientToken($discovery, 'poc-key-operator', getenv('R0_KEY_SECRET'), ['scope' => 'kc-admin-api']);
        $keyAdmin = Http::timeout(10)->withToken($keyToken);
        expect($admin->get("$adminUrl/components")->status() === 403, 'Provisioner can manage key components');
        $realmId = $keyAdmin->get($adminUrl)->throw()->json('id');
        $keyAdmin->post("$adminUrl/components", [
            'name' => 'r0-rotated-signing-key',
            'providerId' => 'rsa-generated',
            'providerType' => 'org.keycloak.keys.KeyProvider',
            'parentId' => $realmId,
            'config' => ['priority' => ['200'], 'enabled' => ['true'], 'active' => ['true'],
                'algorithm' => ['RS256'], 'keySize' => ['2048']],
        ])->throw();
        $newTokens = checkBrowser($discovery, $issuer, $first->subject);
        $rotatedKeys = Http::timeout(10)->get($discovery['jwks_uri'])->throw()->json('keys');
        expect(signingKeyId($newTokens['id_token']) !== $oldKey, 'New ID token still uses the previous signing key');
        expect(validateSignedToken($revoked['id_token'], $rotatedKeys, $issuer, 'poc-browser', time()) !== null,
            'Prior signing key disappeared during overlap');
        echo "PASS signing key rotation and overlap in JWKS\n";

        $admin->put("$adminUrl/users/$first->subject", ['enabled' => false])->throw();
        expect($admin->get("$adminUrl/users/$first->subject")->throw()->json('enabled') === false, 'Disablement not visible');
        expect($introspect($newTokens['access_token']) === false, 'Disabled user access token is still active');
        checkBrowser($discovery, $issuer, $first->subject, disabled: true);
        echo "PASS disabled provider user cannot sign in or introspect as active\n";
    } finally {
        DB::statement("DROP TABLE $table");
    }
}

function base64url(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function checkBrowser(array $discovery, string $issuer, string $subject, bool $disabled = false): array
{
    $redirect = 'http://127.0.0.1:8765/callback';
    $client = 'poc-browser';
    $state = bin2hex(random_bytes(16));
    $nonce = bin2hex(random_bytes(16));
    $verifier = base64url(random_bytes(48));
    $query = [
        'response_type' => 'code',
        'client_id' => $client,
        'redirect_uri' => $redirect,
        'scope' => 'openid',
        'state' => $state,
        'nonce' => $nonce,
        'code_challenge_method' => 'S256',
        'code_challenge' => base64url(hash('sha256', $verifier, true)),
    ];
    $authorization = $discovery['authorization_endpoint'];
    $invalidRedirect = Http::timeout(10)->withoutRedirecting()->get($authorization, [
        ...$query, 'redirect_uri' => 'http://127.0.0.1:8766/callback',
    ]);
    expect($invalidRedirect->status() === 400 && ! $invalidRedirect->header('Location'), 'Unexpected redirect validation result');

    $getCode = static function () use ($authorization, $query, $issuer, $redirect, $state, $disabled): ?string {
        $browser = Http::timeout(10)->withoutRedirecting()->withOptions(['cookies' => new CookieJar]);
        $page = $browser->get($authorization, $query)->throw();
        $html = new DOMDocument;
        expect(@$html->loadHTML($page->body()), 'Login form is invalid');
        $action = $html->getElementById('kc-form-login')?->getAttribute('action');
        expect(is_string($action) && str_starts_with($action, "$issuer/login-actions/"), 'Login form target is unexpected');
        $response = $browser->asForm()->post($action, ['username' => 'r0-user', 'password' => getenv('R0_USER_PASSWORD')]);
        if ($disabled) {
            expect($response->status() === 200 && ! $response->header('Location'), 'Disabled login did not remain on the login page');

            return null;
        }

        $location = $response->header('Location');
        expect($response->redirect() && str_starts_with($location, "$redirect?"), 'Login did not return to the registered callback');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $callback);
        expect(($callback['state'] ?? null) === $state && isset($callback['code']), 'Callback state or authorization code missing');

        return $callback['code'];
    };
    if ($disabled) {
        $getCode();

        return [];
    }

    $exchange = static fn (string $code, ?string $pkce) => Http::timeout(10)->asForm()->withBasicAuth($client, getenv('R0_BROWSER_SECRET'))->post($discovery['token_endpoint'], [
        'grant_type' => 'authorization_code', 'client_id' => $client,
        'redirect_uri' => $redirect, 'code' => $code,
        ...($pkce === null ? [] : ['code_verifier' => $pkce]),
    ]);
    $invalidPkce = $exchange($getCode(), base64url(random_bytes(48)));
    expect($invalidPkce->status() === 400 && $invalidPkce->json('error') === 'invalid_grant', 'Wrong verifier was not rejected');

    $missingPkce = $exchange($getCode(), null);
    expect($missingPkce->status() === 400, 'Missing verifier was not rejected');

    $code = $getCode();
    $exchange($code, $verifier)->throw();
    $replay = $exchange($code, $verifier);
    expect($replay->status() === 400 && $replay->json('error') === 'invalid_grant', 'Used authorization code was accepted');

    $tokens = $exchange($getCode(), $verifier)->throw()->json();
    $jwks = Http::timeout(10)->get($discovery['jwks_uri'])->throw()->json('keys');
    $claims = validateSignedToken($tokens['id_token'], $jwks, $issuer, $client, time());
    $accessClaims = validateSignedToken($tokens['access_token'], $jwks, $issuer, $client, time());
    expect($accessClaims !== null && $accessClaims['sub'] === $subject, 'Access token validation failed');
    expect($claims !== null && $claims['nonce'] === $nonce && $claims['sub'] === $subject, 'ID token did not bind the nonce and provisioned subject');
    expect(validateSignedToken($tokens['id_token'], $jwks, "$issuer/untrusted", $client, time()) === null, 'Issuer mismatch was accepted');
    expect(validateSignedToken($tokens['id_token'], $jwks, $issuer, 'other-client', time()) === null, 'Audience mismatch was accepted');
    expect(validateSignedToken($tokens['id_token'], $jwks, $issuer, $client, $claims['exp']) === null, 'Expiry was not enforced');

    echo "PASS authorization code, PKCE S256, state, nonce, issuer, audience, expiry, invalid redirect, wrong or missing verifier, code reuse rejection\n";

    return $tokens;
}

function signingKeyId(string $jwt): string
{
    $header = explode('.', $jwt)[0];

    return json_decode(base64_decode(strtr($header, '-_', '+/'), true), true, flags: JSON_THROW_ON_ERROR)['kid'];
}

function validateSignedToken(string $jwt, array $keys, string $issuer, string $audience, int $now): ?array
{
    $decode = static fn (string $part): string => base64_decode(strtr($part, '-_', '+/'), true);
    [$header, $payload, $signature] = explode('.', $jwt);
    $metadata = json_decode($decode($header), true, flags: JSON_THROW_ON_ERROR);
    $claims = json_decode($decode($payload), true, flags: JSON_THROW_ON_ERROR);
    if ($metadata['alg'] !== 'RS256' || $claims['iss'] !== $issuer
        || ! in_array($audience, (array) $claims['aud'], true)
        || ! is_int($claims['exp']) || $claims['exp'] <= $now) {
        return null;
    }

    foreach ($keys as $key) {
        if (($key['use'] ?? null) !== 'sig' || $key['kid'] !== $metadata['kid'] || empty($key['x5c'][0])) {
            continue;
        }
        $certificate = "-----BEGIN CERTIFICATE-----\n".chunk_split($key['x5c'][0], 64, "\n")."-----END CERTIFICATE-----\n";

        return openssl_verify("$header.$payload", $decode($signature), $certificate, OPENSSL_ALGO_SHA256) === 1
            ? $claims
            : null;
    }

    return null;
}

try {
    checkRealm();
} catch (RequestException $e) {
    file_put_contents('php://stderr', 'PoC HTTP failure: '.$e->response->status().PHP_EOL);
    exit(1);
} catch (ConnectionException) {
    file_put_contents('php://stderr', "PoC provider connection failure\n");
    exit(1);
} catch (RuntimeException $e) {
    file_put_contents('php://stderr', 'PoC failure: '.$e->getMessage().PHP_EOL);
    exit(1);
}
