<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Ulaxys\Identity\Repositories\Repository\AuditRepository;
use Ulaxys\Identity\Repositories\Repository\AuthSessionRepository;
use Ulaxys\Identity\Repositories\Repository\CredentialChallengeRepository;
use Ulaxys\Identity\Repositories\Repository\HealthRepository;
use Ulaxys\Identity\Repositories\Repository\LoginIdentifierRepository;
use Ulaxys\Identity\Repositories\Repository\PasswordCredentialRepository;
use Ulaxys\Identity\Repositories\Repository\PlatformAdministratorRepository;
use Ulaxys\Identity\Repositories\Repository\RefreshTokenRepository;
use Ulaxys\Identity\Repositories\Repository\SigningKeyRepository;
use Ulaxys\Identity\Repositories\Repository\UserRepository;
use Ulaxys\Identity\Repositories\RepositoryInterface\AuditRepositoryInterface;
use Ulaxys\Identity\Repositories\RepositoryInterface\AuthSessionRepositoryInterface;
use Ulaxys\Identity\Repositories\RepositoryInterface\CredentialChallengeRepositoryInterface;
use Ulaxys\Identity\Repositories\RepositoryInterface\HealthRepositoryInterface;
use Ulaxys\Identity\Repositories\RepositoryInterface\LoginIdentifierRepositoryInterface;
use Ulaxys\Identity\Repositories\RepositoryInterface\PasswordCredentialRepositoryInterface;
use Ulaxys\Identity\Repositories\RepositoryInterface\PlatformAdministratorRepositoryInterface;
use Ulaxys\Identity\Repositories\RepositoryInterface\RefreshTokenRepositoryInterface;
use Ulaxys\Identity\Repositories\RepositoryInterface\SigningKeyRepositoryInterface;
use Ulaxys\Identity\Repositories\RepositoryInterface\UserRepositoryInterface;
use Ulaxys\Identity\Models\LoginIdentifier;
use Ulaxys\Identity\Services\Identity\AuditLogger;
use Ulaxys\Identity\Services\Identity\Delivery\LogTokenDelivery;
use Ulaxys\Identity\Services\Identity\Delivery\NullTokenDelivery;
use Ulaxys\Identity\Services\Identity\Delivery\TokenDeliveryInterface;

final class AppServiceProvider extends ServiceProvider
{
    public $bindings = [
        HealthRepositoryInterface::class => HealthRepository::class,
        UserRepositoryInterface::class => UserRepository::class,
        LoginIdentifierRepositoryInterface::class => LoginIdentifierRepository::class,
        PasswordCredentialRepositoryInterface::class => PasswordCredentialRepository::class,
        AuthSessionRepositoryInterface::class => AuthSessionRepository::class,
        RefreshTokenRepositoryInterface::class => RefreshTokenRepository::class,
        CredentialChallengeRepositoryInterface::class => CredentialChallengeRepository::class,
        PlatformAdministratorRepositoryInterface::class => PlatformAdministratorRepository::class,
        SigningKeyRepositoryInterface::class => SigningKeyRepository::class,
        AuditRepositoryInterface::class => AuditRepository::class,
    ];

    public function boot(): void
    {
        $this->registerRateLimiters();
        $this->bindTokenDelivery();
    }

    /**
     * Rate limit buckets are keyed by peppered hashes so raw identifiers and
     * IPs never appear in cache keys or logs. Configured attempts-per-window
     * are normalized to an explicit per-minute ceiling.
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('identity-login', function (Request $request) {
            $key = AuditLogger::hashWithPepper(LoginIdentifier::normalize((string) $request->input('identifier'))).'|'.AuditLogger::hashWithPepper((string) $request->ip());
            $decay = max(60, (int) config('identity.throttle.login_decay_seconds', 300));
            $max = max(1, (int) config('identity.throttle.login_max_attempts', 8));

            return [Limit::perMinute((int) ceil($max * 60 / $decay))->by($key)];
        });
        RateLimiter::for('identity-refresh', function (Request $request) {
            $key = AuditLogger::hashWithPepper((string) $request->ip());
            $max = max(1, (int) config('identity.throttle.refresh_max_attempts', 30));

            return [Limit::perMinute($max)->by($key)];
        });
        RateLimiter::for('identity-recovery', function (Request $request) {
            $key = AuditLogger::hashWithPepper((string) $request->ip());
            $decay = max(60, (int) config('identity.throttle.recovery_decay_seconds', 900));
            $max = max(1, (int) config('identity.throttle.recovery_max_attempts', 5));

            return [Limit::perMinute(min(30, (int) ceil($max * 60 / $decay)))->by($key)];
        });
        RateLimiter::for('identity-introspect', function (Request $request) {
            $key = AuditLogger::hashWithPepper((string) $request->ip());
            $max = max(1, (int) config('identity.throttle.introspect_max_attempts', 600));

            return [Limit::perMinute(min(600, $max))->by($key)];
        });
        RateLimiter::for('identity-authenticated', function (Request $request) {
            $key = AuditLogger::hashWithPepper((string) $request->ip());
            $max = max(1, (int) config('identity.throttle.authenticated_max_attempts', 240));

            return [Limit::perMinute($max)->by($key)];
        });
    }

    private function bindTokenDelivery(): void
    {
        $this->app->bind(TokenDeliveryInterface::class, function ($app) {
            // The log driver stores one-time tokens in plaintext by design and
            // is documented as local-development only (docs/operator-runbook.md).
            if ((string) config('identity.delivery.driver', 'null') === 'log') {
                if (! $app->environment('local', 'testing')) {
                    throw new \RuntimeException('Plaintext token delivery is restricted to local/testing environments.');
                }

                return new LogTokenDelivery;
            }

            return new NullTokenDelivery;
        });
    }
}
