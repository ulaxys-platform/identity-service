<?php

namespace Ulaxys\Identity\Transformers;

/**
 * Class ResponseObject
 */
abstract class ResponseObject
{
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
