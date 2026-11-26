<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Repositories\RepositoryInterface;

use Ulaxys\Identity\Models\SigningKey;

interface SigningKeyRepositoryInterface
{
    public function active(): ?SigningKey;

    /** Active key plus retired keys within the publication overlap window. */
    public function verifiable(): array;

    public function create(array $attributes): SigningKey;

    public function retireOthers(string $kid): int;
}
