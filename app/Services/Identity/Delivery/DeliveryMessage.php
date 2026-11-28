<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Services\Identity\Delivery;

/** Value object for one-time token delivery; contains the raw token by design. */
final class DeliveryMessage
{
    public function __construct(
        public readonly string $purpose,
        public readonly string $toIdentifier,
        public readonly string $toIdentifierKind,
        public readonly string $token,
        public readonly string $locale,
    ) {}
}
