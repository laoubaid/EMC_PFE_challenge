<?php

declare(strict_types=1);

namespace App\Tests\Routing;

use App\Entity\Trip;
use App\Message\RefreshTripRoute;
use App\MessageHandler\RefreshTripRouteHandler;
use App\Repository\TripRepository;
use App\Routing\RoutingUnavailableException;
use App\Routing\TripRouteUpdater;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class RefreshTripRouteHandlerTest extends TestCase
{
    private TripRepository $tripRepository;
    private TripRouteUpdater $tripRouteUpdater;
    private RefreshTripRouteHandler $handler;

    protected function setUp(): void
    {
        $this->tripRepository = $this->createMock(TripRepository::class);
        $this->tripRouteUpdater = $this->createMock(TripRouteUpdater::class);
        $this->handler = new RefreshTripRouteHandler(
            $this->tripRepository,
            $this->tripRouteUpdater,
            new NullLogger()
        );
    }

    public function testInvokeCallsTripRouteUpdaterWithFoundTrip(): void
    {
        $trip = new Trip();
        $this->tripRepository->expects($this->once())
            ->method('find')
            ->with(10)
            ->willReturn($trip);

        $this->tripRouteUpdater->expects($this->once())
            ->method('refresh')
            ->with($trip);

        $message = new RefreshTripRoute(10);
        ($this->handler)($message);
    }

    public function testInvokeDoesNotCallUpdaterWhenTripNotFound(): void
    {
        $this->tripRepository->expects($this->once())
            ->method('find')
            ->with(999)
            ->willReturn(null);

        $this->tripRouteUpdater->expects($this->never())
            ->method('refresh');

        $message = new RefreshTripRoute(999);
        ($this->handler)($message);
    }

    public function testInvokeHandlesRoutingUnavailableExceptionGracefullyWithoutThrowing(): void
    {
        $trip = new Trip();
        $this->tripRepository->expects($this->once())
            ->method('find')
            ->with(10)
            ->willReturn($trip);

        $this->tripRouteUpdater->expects($this->once())
            ->method('refresh')
            ->with($trip)
            ->willThrowException(new RoutingUnavailableException('Quota exceeded', 403));

        $message = new RefreshTripRoute(10);
        // Calling handler must not throw, allowing the trip to remain saved
        ($this->handler)($message);
    }
}
