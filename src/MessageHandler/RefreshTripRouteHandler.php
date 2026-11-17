<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\RefreshTripRoute;
use App\Repository\TripRepository;
use App\Routing\RoutingUnavailableException;
use App\Routing\TripRouteUpdater;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RefreshTripRouteHandler
{
    public function __construct(
        private TripRepository $tripRepository,
        private TripRouteUpdater $tripRouteUpdater,
        private LoggerInterface $logger = new NullLogger()
    ) {
    }

    public function __invoke(RefreshTripRoute $message): void
    {
        $trip = $this->tripRepository->find($message->tripId);

        if ($trip === null) {
            $this->logger->warning(sprintf('RefreshTripRouteHandler: Trip #%d not found.', $message->tripId));
            return;
        }

        try {
            $this->tripRouteUpdater->refresh($trip);
            $this->logger->info(sprintf('RefreshTripRouteHandler: Route refreshed successfully for Trip #%d.', $trip->getId()));
        } catch (RoutingUnavailableException $e) {
            $this->logger->warning(sprintf(
                'RefreshTripRouteHandler: Routing service unavailable for Trip #%d: %s. Route values left as null.',
                $trip->getId(),
                $e->getMessage()
            ));
            // An ORS failure must never block saving the trip (leave values null)
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf(
                'RefreshTripRouteHandler: Unexpected error refreshing route for Trip #%d: %s. Route values left as null.',
                $trip->getId(),
                $e->getMessage()
            ));
            // Never block saving the trip
        }
    }
}
