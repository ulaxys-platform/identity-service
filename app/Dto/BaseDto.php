<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Dto;

abstract class BaseDto
{
    /** Hydrate an already-mapped contract without retaining an Eloquent model. */
    public static function fromArray(array $values): static
    {
        $dto = new static;
        foreach ($values as $name => $value) {
            if (! property_exists($dto, $name)) {
                throw new \InvalidArgumentException('Unknown DTO field: '.$name);
            }
            $dto->$name = $value;
        }

        return $dto;
    }

    public function toArray(): array
    {
        return array_map(self::serializeValue(...), get_object_vars($this));
    }

    private static function serializeValue(mixed $value): mixed
    {
        if ($value instanceof self) {
            return $value->toArray();
        }
        if (is_array($value)) {
            return array_map(self::serializeValue(...), $value);
        }

        return $value;
    }
}
