<?php

namespace Ulaxys\Identity\Transformers;

use OpenApi\Attributes as OA;

#[OA\Schema(schema: 'HttpSuccessResponseObject', required: ['size', 'status', 'message'])]
class HttpSuccessResponseObject extends ResponseObject
{
    #[OA\Property]
    protected int $size = 0;

    #[OA\Property]
    protected int $status;

    #[OA\Property]
    protected string $message;

    public function getSize(): int
    {
        return $this->size;
    }

    public function setSize(int $size): static
    {
        $this->size = $size;

        return $this;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function setStatus(int $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setMessage(string $message): static
    {
        $this->message = $message;

        return $this;
    }
}
