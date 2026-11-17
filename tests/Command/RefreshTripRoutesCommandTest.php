<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\RefreshTripRoutesCommand;
use App\Entity\City;
use App\Entity\Trip;
use App\Entity\Waypoint;
use App\Repository\TripRepository;
use App\Routing\TripRouteUpdater;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class RefreshTripRoutesCommandTest extends TestCase
{
    public function testExecuteRefreshesTripsNeedingRoutes(): void
    {
        $cityCasa = new City();
        $cityCasa->setName('Casablanca');

        $cityRabat = new City();
        $cityRabat->setName('Rabat');

        $trip = new Trip();
        $wp0 = new Waypoint();
        $wp0->setCity($cityCasa);
        $wp0->setStopOrder(0);
        $wp0->setDistanceFromOriginKm(0.0);
        $wp0->setDurationFromOriginMin(0);
        $trip->addWaypoint($wp0);

        $wp1 = new Waypoint();
        $wp1->setCity($cityRabat);
        $wp1->setStopOrder(1);
        $wp1->setDistanceFromOriginKm(85.0);
        $wp1->setDurationFromOriginMin(null); // Needs refresh!
        $trip->addWaypoint($wp1);

        $tripRepo = $this->createMock(TripRepository::class);
        $tripRepo->expects($this->once())
            ->method('findAll')
            ->willReturn([$trip]);

        $updater = $this->createMock(TripRouteUpdater::class);
        $updater->expects($this->once())
            ->method('refresh')
            ->with($trip);

        $command = new RefreshTripRoutesCommand($tripRepo, $updater);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--no-delay' => true]);

        $this->assertSame(0, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Backfilling route data', $display);
        $this->assertStringContainsString('Backfill complete! 1 refreshed successfully', $display);
    }

    public function testExecuteWhenAllTripsAlreadyHaveData(): void
    {
        $trip = new Trip();
        $trip->setRouteGeometry(['type' => 'LineString', 'coordinates' => []]);

        $wp0 = new Waypoint();
        $wp0->setStopOrder(0);
        $wp0->setDurationFromOriginMin(0);
        $trip->addWaypoint($wp0);

        $wp1 = new Waypoint();
        $wp1->setStopOrder(1);
        $wp1->setDurationFromOriginMin(60);
        $trip->addWaypoint($wp1);

        $tripRepo = $this->createMock(TripRepository::class);
        $tripRepo->expects($this->once())
            ->method('findAll')
            ->willReturn([$trip]);

        $updater = $this->createMock(TripRouteUpdater::class);
        $updater->expects($this->never())->method('refresh');

        $command = new RefreshTripRoutesCommand($tripRepo, $updater);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('All trips already have up-to-date route durations', $tester->getDisplay());
    }
}
