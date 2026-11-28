<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Services\Identity\Delivery;

/** Default driver: audit-only. Nothing is transmitted or persisted. */
final class NullTokenDelivery implements TokenDeliveryInterface
{
    public function deliver(DeliveryMessage $message): bool
    {
        return true;
    }
}
