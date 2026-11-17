<?php

declare(strict_types=1);

namespace App\Routing;

readonly class RouteResult
{
    /**
     * @param list<StopEta> $etas Cumulative ETAs for each point (index 0 is always 0.0 km / 0 min)
     * @param array{type: string, coordinates: list<array{0: float, 1: float}>}|null $geometry GeoJSON LineString
     */
    public function __construct(
        public array $etas,
        public ?array $geometry = null
    ) {
    }
}
