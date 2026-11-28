<?php

namespace Ulaxys\Identity\Contracts;

/**
 * Consumer-owned, read-only verification using the same configured client and
 * authentication path as application traffic. Never implement write operations.
 */
interface DependencyProbe
{
    /**
     * Return true only when the declared check has actually been verified.
     * Throw on transport or contract failure. Do not return secret diagnostics.
     */
    public function check(string $check): bool;
}
