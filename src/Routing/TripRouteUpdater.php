<?php

declare(strict_types=1);

namespace App\Routing;

use App\Entity\Trip;
use App\Entity\Waypoint;
use App\Message\RefreshTripRoute;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;

class TripRouteUpdater
{
    public function __construct(
        private readonly RoutingService $routingService,
        private readonly EntityManagerInterface $entityManager,
        private readonly ?MessageBusInterface $messageBus = null,
        private readonly LoggerInterface $logger = new NullLogger()
    ) {
    }

    /**
     * Refreshes the trip's route by querying the RoutingService with the cities' coordinates,
     * writes the cumulative values onto each stop (Waypoint), and flushes the entity manager.
     */
    public function refresh(Trip $trip): void
    {
        $orderedStops = $trip->getOrderedWaypoints();

        if (count($orderedStops) < 2) {
            $this->logger->warning(sprintf('Trip #%d has fewer than 2 stops; cannot compute route.', $trip->getId() ?? 0));
            return;
        }

        $points = [];
        foreach ($orderedStops as $stop) {
            $city = $stop->getCity();
            if ($city === null || $city->getLatitude() === null || $city->getLongitude() === null) {
                $this->logger->warning(sprintf('Stop #%d in trip #%d has no city coordinates.', $stop->getStopOrder(), $trip->getId() ?? 0));
                return;
            }

            $points[] = [
                'lat' => $city->getLatitude(),
                'lon' => $city->getLongitude(),
            ];
        }

        // Call RoutingService (throws RoutingUnavailableException on failure)
        $routeResult = $this->routingService->computeRoute($points);
        $etas = $routeResult->etas;

        $depTime = $trip->getDepartureTime();

        foreach ($orderedStops as $index => $stop) {
            if (!isset($etas[$index])) {
                continue;
            }

            $eta = $etas[$index];
            $stop->setDistanceFromOriginKm($eta->distanceKm);
            $stop->setDurationFromOriginMin($eta->durationMin);

            // Update arrival time if trip has departure time
            if ($depTime !== null) {
                $stop->setArrivalTime($depTime->modify(sprintf('+%d minutes', $eta->durationMin)));
            }
        }

        // Store GeoJSON LineString route geometry for map visualization
        if ($routeResult->geometry !== null) {
            $trip->setRouteGeometry($routeResult->geometry);
        }

        $this->entityManager->flush();
    }

    /**
     * Dispatches a RefreshTripRoute message via Messenger if configured,
     * or executes synchronously inside a try/catch if Messenger is not available.
     */
    public function triggerRefresh(Trip $trip): void
    {
        if ($trip->getId() === null) {
            $this->entityManager->flush();
        }

        if ($this->messageBus !== null) {
            $this->messageBus->dispatch(new RefreshTripRoute($trip->getId()));
        } else {
            try {
                $this->refresh($trip);
            } catch (RoutingUnavailableException $e) {
                $this->logger->warning(sprintf('Routing unavailable when updating trip #%d: %s', $trip->getId(), $e->getMessage()));
            } catch (\Throwable $e) {
                $this->logger->warning(sprintf('Failed to refresh route for trip #%d: %s', $trip->getId(), $e->getMessage()));
            }
        }
    }

    /**
     * Helper returning distanceKm and durationMin by subtraction between any two stops.
     * With NO extra API call.
     */
    public static function segment(Waypoint $from, Waypoint $to): StopEta
    {
        $distanceKm = round(abs((float) $to->getDistanceFromOriginKm() - (float) $from->getDistanceFromOriginKm()), 1);

        $toDuration = $to->getDurationFromOriginMin() ?? 0;
        $fromDuration = $from->getDurationFromOriginMin() ?? 0;
        $durationMin = abs($toDuration - $fromDuration);

        return new StopEta(
            distanceKm: $distanceKm,
            durationMin: $durationMin
        );
    }

    /**
     * Computes the display duration in minutes, adding ~10% plus 5 minutes per intermediate stop
     * as a buffer to displayed durations only (leaves raw stored ORS values intact).
     */
    public static function calculateBufferedDuration(int $rawDurationMin, int $intermediateStopsCount = 0): int
    {
        return (int) round($rawDurationMin * 1.10) + (max(0, $intermediateStopsCount) * 5);
    }

    /**
     * Formats duration into human-readable string: "~X h Y min" or "~Y min".
     */
    public static function formatDuration(int $minutes): string
    {
        if ($minutes <= 0) {
            return '~0 min';
        }

        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;

        if ($hours > 0 && $mins > 0) {
            return sprintf('~%d h %d min', $hours, $mins);
        }

        if ($hours > 0) {
            return sprintf('~%d h', $hours);
        }

        return sprintf('~%d min', $mins);
    }

    /**
     * Returns formatted display string: "~X h Y min · Z km (approx.)"
     */
    public static function formatDisplayEta(float $distanceKm, int $rawDurationMin, int $intermediateStopsCount = 0): string
    {
        $buffered = self::calculateBufferedDuration($rawDurationMin, $intermediateStopsCount);
        $timeStr = self::formatDuration($buffered);

        return sprintf('%s · %.1f km (approx.)', $timeStr, $distanceKm);
    }
}
