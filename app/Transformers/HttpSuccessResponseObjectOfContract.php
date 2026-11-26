<?php

namespace Ulaxys\Identity\Transformers;

use Ulaxys\Identity\Dto\BaseDto;

/** Subclasses declare an operation-specific typed $items property. */
abstract class HttpSuccessResponseObjectOfContract extends HttpSuccessResponseObject
{
    public function toArray(): array
    {
        $body = parent::toArray();
        $body['items'] = $body['items'] instanceof BaseDto ? $body['items']->toArray() : array_map(fn (BaseDto $item) => $item->toArray(), $body['items']);

        return $body;
    }
}
