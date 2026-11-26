<?php

namespace Ulaxys\Identity\Transformers\ErrorResponseObjects;

use OpenApi\Attributes\Property;
use OpenApi\Attributes\Schema;
use Ulaxys\Identity\Transformers\ErrorResponseObject;

#[Schema(schema: 'HttpErrorResponseObjectOfResult', required: ['status', 'code', 'message'])]
class HttpErrorResponseObjectOfResult extends ErrorResponseObject
{
    #[Property(type: 'string')]
    protected string $message;

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setMessage(string $message): self
    {
        $this->message = $message;

        return $this;
    }
}
