<?php

namespace Ulaxys\Identity\Transformers;

use OpenApi\Attributes\Property;

abstract class ErrorResponseObject extends ResponseObject
{
    #[Property(type: 'integer')]
    protected int $status;

    #[Property(type: 'integer')]
    protected int $code;

    public function getStatus(): int
    {
        return $this->status;
    }

    public function setStatus(int $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getCode(): int
    {
        return $this->code;
    }

    public function setCode(int $code): self
    {
        $this->code = $code;

        return $this;
    }
}
