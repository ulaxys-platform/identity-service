<?php

namespace Ulaxys\Identity\Tests\Fixtures\Integration;

final class FixtureController
{
    public static int $calls = 0;

    public function handle(): never
    {
        self::$calls++;
        throw new \LogicException('Discovery must never execute handlers.');
    }
}
