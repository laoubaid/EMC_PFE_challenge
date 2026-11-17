<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Waypoint;
use App\Routing\StopEta;
use App\Routing\TripRouteUpdater;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class RoutingExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('display_stop_eta', [$this, 'displayStopEta']),
            new TwigFunction('display_segment_eta', [$this, 'displaySegmentEta']),
            new TwigFunction('trip_segment', [$this, 'tripSegment']),
            new TwigFunction('format_duration', [$this, 'formatDuration']),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('format_duration', [$this, 'formatDuration']),
        ];
    }

    /**
     * Formats cumulative ETA from start: "~X h Y min · Z km (approx.)"
     */
    public function displayStopEta(Waypoint $waypoint): string
    {
        return $waypoint->getDisplayEtaFromOrigin();
    }

    /**
     * Formats segment ETA between boarding and alighting stops: "~X h Y min · Z km (approx.)"
     */
    public function displaySegmentEta(Waypoint $from, Waypoint $to): string
    {
        $eta = TripRouteUpdater::segment($from, $to);
        $intermediateStopsCount = max(0, abs(($to->getStopOrder() ?? 0) - ($from->getStopOrder() ?? 0)) - 1);

        return TripRouteUpdater::formatDisplayEta($eta->distanceKm, $eta->durationMin, $intermediateStopsCount);
    }

    /**
     * Returns StopEta DTO for a segment between two waypoints.
     */
    public function tripSegment(Waypoint $from, Waypoint $to): StopEta
    {
        return TripRouteUpdater::segment($from, $to);
    }

    public function formatDuration(int $minutes): string
    {
        return TripRouteUpdater::formatDuration($minutes);
    }
}
