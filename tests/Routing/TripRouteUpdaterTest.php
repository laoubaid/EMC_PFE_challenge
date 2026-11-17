<?php

declare(strict_types=1);

namespace App\Tests\Routing;

use App\Entity\City;
use App\Entity\Trip;
use App\Entity\Waypoint;
use App\Message\RefreshTripRoute;
use App\Routing\RoutingService;
use App\Routing\StopEta;
use App\Routing\TripRouteUpdater;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class TripRouteUpdaterTest extends TestCase
{
    private RoutingService $routingService;
    private EntityManagerInterface $entityManager;
    private MessageBusInterface $messageBus;
    private TripRouteUpdater $updater;

    protected function setUp(): void
    {
        $this->routingService = $this->createMock(RoutingService::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);

        $this->updater = new TripRouteUpdater(
            $this->routingService,
            $this->entityManager,
            $this->messageBus
        );
    }

    public function testRefreshUpdatesWaypointsCumulativeValuesAndArrivalTimes(): void
    {
        $cityCasa = new City();
        $cityCasa->setName('Casablanca');
        $cityCasa->setLatitude(33.5731);
        $cityCasa->setLongitude(-7.5898);

        $cityRabat = new City();
        $cityRabat->setName('Rabat');
        $cityRabat->setLatitude(34.0209);
        $cityRabat->setLongitude(-6.8416);

        $cityTangier = new City();
        $cityTangier->setName('Tangier');
        $cityTangier->setLatitude(35.7595);
        $cityTangier->setLongitude(-5.8340);

        $departureTime = new \DateTimeImmutable('2026-11-20 08:00:00');

        $trip = new Trip();
        $trip->setDepartureTime($departureTime);

        $wp0 = new Waypoint();
        $wp0->setCity($cityCasa);
        $wp0->setStopOrder(0);
        $wp0->setArrivalTime($departureTime);
        $trip->addWaypoint($wp0);

        $wp1 = new Waypoint();
        $wp1->setCity($cityRabat);
        $wp1->setStopOrder(1);
        $wp1->setArrivalTime($departureTime);
        $trip->addWaypoint($wp1);

        $wp2 = new Waypoint();
        $wp2->setCity($cityTangier);
        $wp2->setStopOrder(2);
        $wp2->setArrivalTime($departureTime);
        $trip->addWaypoint($wp2);

        $expectedEtas = [
            new StopEta(0.0, 0),
            new StopEta(85.4, 62),
            new StopEta(335.5, 215),
        ];

        $expectedGeometry = [
            'type' => 'LineString',
            'coordinates' => [[-7.5898, 33.5731], [-6.8416, 34.0209], [-5.8340, 35.7595]],
        ];

        $this->routingService->expects($this->once())
            ->method('computeRoute')
            ->with([
                ['lat' => 33.5731, 'lon' => -7.5898],
                ['lat' => 34.0209, 'lon' => -6.8416],
                ['lat' => 35.7595, 'lon' => -5.8340],
            ])
            ->willReturn(new \App\Routing\RouteResult(etas: $expectedEtas, geometry: $expectedGeometry));

        $this->entityManager->expects($this->once())
            ->method('flush');

        $this->updater->refresh($trip);

        // Assert Trip Geometry was stored
        $this->assertSame($expectedGeometry, $trip->getRouteGeometry());

        // Assert Origin stop (order 0)
        $this->assertEquals('0.00', $wp0->getDistanceFromOriginKm());
        $this->assertSame(0, $wp0->getDurationFromOriginMin());
        $this->assertEquals('2026-11-20 08:00:00', $wp0->getArrivalTime()->format('Y-m-d H:i:s'));

        // Assert Intermediate stop (order 1)
        $this->assertEquals('85.40', $wp1->getDistanceFromOriginKm());
        $this->assertSame(62, $wp1->getDurationFromOriginMin());
        $this->assertEquals('2026-11-20 09:02:00', $wp1->getArrivalTime()->format('Y-m-d H:i:s'));

        // Assert Destination stop (order 2)
        $this->assertEquals('335.50', $wp2->getDistanceFromOriginKm());
        $this->assertSame(215, $wp2->getDurationFromOriginMin());
        $this->assertEquals('2026-11-20 11:35:00', $wp2->getArrivalTime()->format('Y-m-d H:i:s'));
    }

    public function testSegmentHelperReturnsExactSubtractionWithoutExtraApiCall(): void
    {
        $wpFrom = new Waypoint();
        $wpFrom->setDistanceFromOriginKm(85.4);
        $wpFrom->setDurationFromOriginMin(62);

        $wpTo = new Waypoint();
        $wpTo->setDistanceFromOriginKm(335.5);
        $wpTo->setDurationFromOriginMin(215);

        $segment = TripRouteUpdater::segment($wpFrom, $wpTo);

        $this->assertSame(250.1, $segment->distanceKm);
        $this->assertSame(153, $segment->durationMin);

        // Subtraction is order-invariant (from -> to or to -> from)
        $reverseSegment = TripRouteUpdater::segment($wpTo, $wpFrom);
        $this->assertSame(250.1, $reverseSegment->distanceKm);
        $this->assertSame(153, $reverseSegment->durationMin);
    }

    public function testCalculateBufferedDurationAddsTenPercentPlusFiveMinPerIntermediateStop(): void
    {
        // 0 intermediate stops: raw 60 min -> 60 * 1.10 = 66 min
        $this->assertSame(66, TripRouteUpdater::calculateBufferedDuration(60, 0));

        // 1 intermediate stop: raw 60 min -> 66 + 5 = 71 min
        $this->assertSame(71, TripRouteUpdater::calculateBufferedDuration(60, 1));

        // 2 intermediate stops: raw 120 min -> 132 + 10 = 142 min
        $this->assertSame(142, TripRouteUpdater::calculateBufferedDuration(120, 2));

        // Negative intermediate count treated as 0
        $this->assertSame(66, TripRouteUpdater::calculateBufferedDuration(60, -1));
    }

    public function testFormatDuration(): void
    {
        $this->assertSame('~0 min', TripRouteUpdater::formatDuration(0));
        $this->assertSame('~45 min', TripRouteUpdater::formatDuration(45));
        $this->assertSame('~1 h', TripRouteUpdater::formatDuration(60));
        $this->assertSame('~2 h 15 min', TripRouteUpdater::formatDuration(135));
    }

    public function testFormatDisplayEta(): void
    {
        // 100 km, 60 min raw, 1 intermediate stop -> buffered 71 min (~1 h 11 min)
        $formatted = TripRouteUpdater::formatDisplayEta(100.0, 60, 1);
        $this->assertSame('~1 h 11 min · 100.0 km (approx.)', $formatted);
    }

    public function testTriggerRefreshDispatchesMessengerMessageWhenBusPresent(): void
    {
        $trip = $this->createMock(Trip::class);
        $trip->method('getId')->willReturn(42);

        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($message) {
                return $message instanceof RefreshTripRoute && $message->tripId === 42;
            }))
            ->willReturn(new Envelope(new RefreshTripRoute(42)));

        $this->updater->triggerRefresh($trip);
    }
}
