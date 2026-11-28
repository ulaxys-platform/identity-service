<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Services\Identity\Delivery;

use Illuminate\Support\Facades\File;
use JsonException;

/**
 * Local-development driver: appends one JSON line per message to a dedicated
 * file. NEVER for production: it stores one-time tokens in plaintext by
 * design, documented in docs/operator-runbook.md.
 */
final class LogTokenDelivery implements TokenDeliveryInterface
{
    public function deliver(DeliveryMessage $message): bool
    {
        $path = (string) config('identity.delivery.log_path');
        if ($path === '') {
            return false;
        }
        $payload = [
            'purpose' => $message->purpose,
            'to' => $message->toIdentifier,
            'locale' => $message->locale,
            'token' => $message->token,
            'delivered_at' => now()->toIso8601String(),
        ];
        try {
            $line = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }
        File::append($path, $line.PHP_EOL);

        return true;
    }
}
