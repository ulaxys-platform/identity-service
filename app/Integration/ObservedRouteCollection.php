<?php

namespace Ulaxys\Identity\Integration;

use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;

/** CLI-only registration history: Laravel otherwise overwrites exact duplicate routes. */
final class ObservedRouteCollection extends RouteCollection
{
    private array $registrations = [];

    public function add(Route $route)
    {
        $this->registrations[] = $route;

        return parent::add($route);
    }

    public function registrations(): array
    {
        return $this->registrations;
    }
}
