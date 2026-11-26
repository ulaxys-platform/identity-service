<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Services\Identity;

/**
 * NIST SP 800-63B aligned password policy (ADR-0003): length bounds, a
 * static blocklist and rejection of identifier-derived passwords. Forced
 * composition rules are deliberately absent. Violations are returned as
 * stable message keys translated by the localization files.
 */
final class PasswordPolicy
{
    public const TOO_SHORT = 'identity.credentials.policy.too_short';

    public const TOO_LONG = 'identity.credentials.policy.too_long';

    public const BLOCKLISTED = 'identity.credentials.policy.blocklisted';

    public const IDENTIFIER_SIMILAR = 'identity.credentials.policy.identifier_similar';

    /** @param list<string> $disallowedSeeds account-specific values (identifiers, display name) */
    public function violations(string $password, array $disallowedSeeds = []): array
    {
        $violations = [];
        $minLength = max(1, (int) config('identity.password.min_length', 12));
        $maxLength = max($minLength, (int) config('identity.password.max_length', 128));
        if (mb_strlen($password) < $minLength) {
            $violations[] = self::TOO_SHORT;
        }
        if (mb_strlen($password) > $maxLength) {
            $violations[] = self::TOO_LONG;
        }
        foreach ((array) config('identity.password.blocklist', []) as $blocked) {
            if (is_string($blocked) && $blocked !== '' && strcasecmp($blocked, $password) === 0) {
                $violations[] = self::BLOCKLISTED;
                break;
            }
        }
        $lower = mb_strtolower($password);
        foreach ($disallowedSeeds as $seed) {
            if (! is_string($seed)) {
                continue;
            }
            $normalized = mb_strtolower(trim($seed));
            if (mb_strlen($normalized) >= 3 && str_contains($lower, $normalized)) {
                $violations[] = self::IDENTIFIER_SIMILAR;
                break;
            }
        }

        return $violations;
    }
}
