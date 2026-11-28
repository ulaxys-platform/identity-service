<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Services\Identity\Tokens;

use RuntimeException;

/**
 * Typed token failure. Reasons stay machine-stable and never leak internal
 * detail to clients; callers translate them into generic responses.
 */
final class TokenException extends RuntimeException
{
    public const MALFORMED = 'malformed';

    public const UNSUPPORTED_ALGORITHM = 'unsupported_algorithm';

    public const UNKNOWN_KID = 'unknown_kid';

    public const INVALID_SIGNATURE = 'invalid_signature';

    public const EXPIRED = 'expired';

    public const NOT_YET_VALID = 'not_yet_valid';

    public const ISSUER_MISMATCH = 'issuer_mismatch';

    public const AUDIENCE_MISMATCH = 'audience_mismatch';

    public const INVALID_CLAIMS = 'invalid_claims';

    public const REUSE_DETECTED = 'reuse_detected';

    public const INVALID_REFRESH = 'invalid_refresh';

    public const KEYS_UNAVAILABLE = 'keys_unavailable';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
