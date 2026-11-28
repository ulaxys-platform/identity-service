<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Services\Identity\Delivery;

/**
 * One-time token transport abstraction. Production transports (SMTP or an
 * internal notification service) are integrated by deployment; the default
 * driver delivers nothing (audit-only) so automated acceptance never sends
 * real email.
 */
interface TokenDeliveryInterface
{
    public function deliver(DeliveryMessage $message): bool;
}
