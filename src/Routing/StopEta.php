<?php

declare(strict_types=1);

namespace App\Routing;

final readonly class StopEta
{
    public function __construct(
        public float $distanceKm,
        public int $durationMin
    ) {
    }
}
