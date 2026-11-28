<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Tests\Support\Identity;

use Ulaxys\Identity\Services\Identity\Delivery\DeliveryMessage;
use Ulaxys\Identity\Services\Identity\Delivery\TokenDeliveryInterface;

/** Test-only transport: captures messages instead of sending anything. */
final class CapturingDelivery implements TokenDeliveryInterface
{
    /** @var list<DeliveryMessage> */
    public array $messages = [];

    public function deliver(DeliveryMessage $message): bool
    {
        $this->messages[] = $message;

        return true;
    }

    public function lastToken(string $purpose): ?string
    {
        foreach (array_reverse($this->messages) as $message) {
            if ($message->purpose === $purpose) {
                return $message->token;
            }
        }

        return null;
    }
}
