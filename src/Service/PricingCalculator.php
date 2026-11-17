<?php

namespace App\Service;

use App\Entity\PricingConfig;
use App\Entity\Trip;
use App\Entity\User;
use App\Entity\Waypoint;
use App\Repository\PricingConfigRepository;
use App\Routing\TripRouteUpdater;

class PricingCalculator
{
    public function __construct(
        private readonly ?PricingConfigRepository $pricingConfigRepository = null
    ) {
    }

    /**
     * Calculates the price for a segment based on DECISIONS.md:
     * - Base price = distance (km) * pricePerKm (e.g. 5 DH/km)
     * - Negotiation margin = baseMargin (e.g. 5%) + 1% for each point above 3 in driver rating
     * Price = Base Price + (Base Price * (baseMargin + max(0, rating - 3) * 0.01))
     */
    public function calculateSegmentPrice(
        float $distanceKm,
        float $driverRating = 4.0,
        ?PricingConfig $config = null
    ): float {
        if ($distanceKm <= 0.0) {
            return 0.0;
        }

        $activeConfig = $config ?? $this->pricingConfigRepository?->getActiveConfig();
        $pricePerKm = $activeConfig ? (float) $activeConfig->getPricePerKm() : 1.5;
        $baseMargin = $activeConfig ? (float) $activeConfig->getBaseMargin() : 0.05;

        $ratingBonus = max(0.0, $driverRating - 3.0) * 0.01;
        $totalMargin = $baseMargin + $ratingBonus;

        $baseAmount = $distanceKm * $pricePerKm;
        $finalPrice = $baseAmount * (1.0 + $totalMargin);

        return round($finalPrice, 2);
    }

    /**
     * Calculate price between two waypoints on a trip.
     */
    public function calculateBetweenWaypoints(
        Waypoint $board,
        Waypoint $alight,
        ?Trip $trip = null,
        ?PricingConfig $config = null
    ): float {
        $segment = TripRouteUpdater::segment($board, $alight);
        $effectiveTrip = $trip ?? $board->getTrip();
        $driverRating = $effectiveTrip?->getDriver()?->getRatingAvg() ?? 4.0;

        return $this->calculateSegmentPrice($segment->distanceKm, $driverRating, $config);
    }
}
