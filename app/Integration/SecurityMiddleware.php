<?php

namespace Ulaxys\Identity\Integration;

use Attribute;

/** Source-reviewed declaration, not proof of runtime enforcement. */
#[Attribute(Attribute::TARGET_CLASS)]
final class SecurityMiddleware
{
    public function __construct(public readonly string $kind) {}
}
