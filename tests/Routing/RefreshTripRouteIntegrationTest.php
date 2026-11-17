<?php

declare(strict_types=1);

namespace App\Tests\Routing;

use App\Entity\City;
use App\Entity\Trip;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Entity\Waypoint;
use App\Message\RefreshTripRoute;
use App\Routing\RoutingService;
use App\Routing\StopEta;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

class RefreshTripRouteIntegrationTest extends KernelTestCase
{
    public function testMessengerDispatchesAndUpdatesTripRouteSynchronouslyInTestEnv(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $em = $container->get('doctrine.orm.entity_manager');
        $schemaTool = new \Doctrine\ORM\Tools\SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $driver = new User();
        $driver->setEmail('driver_routing@test.com');
        $driver->setFirstName('Ali');
        $driver->setLastName('Idrissi');
        $driver->addRole(User::ROLE_DRIVER);
        $driver->setPassword('hash');
        $em->persist($driver);

        $vehicle = new Vehicle();
        $vehicle->setOwner($driver);
        $vehicle->setBrand('Dacia');
        $vehicle->setModel('Sandero');
        $vehicle->setPlate('99999-B-1');
        $vehicle->setSeats(4);
        $em->persist($vehicle);

        $city1 = new City();
        $city1->setName('Casablanca');
        $city1->setLatitude(33.5731);
        $city1->setLongitude(-7.5898);
        $em->persist($city1);

        $city2 = new City();
        $city2->setName('Rabat');
        $city2->setLatitude(34.0209);
        $city2->setLongitude(-6.8416);
        $em->persist($city2);

        $depTime = new \DateTimeImmutable('2026-11-25 10:00:00');

        $trip = new Trip();
        $trip->setDriver($driver);
        $trip->setVehicle($vehicle);
        $trip->setDepartureTime($depTime);
        $trip->setTotalSeats(3);
        $trip->setStatus(Trip::STATUS_SCHEDULED);

        $wp0 = new Waypoint();
        $wp0->setCity($city1);
        $wp0->setStopOrder(0);
        $wp0->setArrivalTime($depTime);
        $trip->addWaypoint($wp0);

        $wp1 = new Waypoint();
        $wp1->setCity($city2);
        $wp1->setStopOrder(1);
        $wp1->setArrivalTime($depTime->modify('+2 hours'));
        $trip->addWaypoint($wp1);

        $em->persist($trip);
        $em->flush();

        $tripId = $trip->getId();
        $this->assertNotNull($tripId);

        // Replace RoutingService in container with a mock returning known StopEtas
        $mockRouting = $this->createMock(RoutingService::class);
        $mockRouting->expects($this->once())
            ->method('computeRoute')
            ->willReturn(new \App\Routing\RouteResult(
                etas: [
                    new StopEta(0.0, 0),
                    new StopEta(87.5, 58),
                ],
                geometry: [
                    'type' => 'LineString',
                    'coordinates' => [[-7.5898, 33.5731], [-6.8416, 34.0209]],
                ]
            ));

        $container->set(RoutingService::class, $mockRouting);

        /** @var MessageBusInterface $bus */
        $bus = $container->get('messenger.default_bus');
        $bus->dispatch(new RefreshTripRoute($tripId));

        // Clear em to ensure fresh fetch from database
        $em->clear();

        /** @var Trip $refreshedTrip */
        $refreshedTrip = $em->getRepository(Trip::class)->find($tripId);
        $orderedWaypoints = $refreshedTrip->getOrderedWaypoints();

        $this->assertCount(2, $orderedWaypoints);

        // Check wp0
        $this->assertEquals(0.0, (float) $orderedWaypoints[0]->getDistanceFromOriginKm());
        $this->assertSame(0, $orderedWaypoints[0]->getDurationFromOriginMin());

        // Check wp1
        $this->assertEquals(87.5, (float) $orderedWaypoints[1]->getDistanceFromOriginKm());
        $this->assertSame(58, $orderedWaypoints[1]->getDurationFromOriginMin());
        $this->assertEquals('2026-11-25 10:58:00', $orderedWaypoints[1]->getArrivalTime()->format('Y-m-d H:i:s'));
    }
}
