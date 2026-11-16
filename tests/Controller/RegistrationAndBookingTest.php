<?php

namespace App\Tests\Controller;

use App\Entity\City;
use App\Entity\PricingConfig;
use App\Entity\Trip;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Entity\Waypoint;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class RegistrationAndBookingTest extends WebTestCase
{
    private function prepareTestData($container): array
    {
        $em = $container->get('doctrine.orm.entity_manager');
        $schemaTool = new \Doctrine\ORM\Tools\SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        // Pricing Config
        $pricing = new PricingConfig();
        $pricing->setPricePerKm(5.0);
        $pricing->setBaseMargin(0.05);
        $em->persist($pricing);

        // Cities
        $casa = new City();
        $casa->setName('Casablanca');
        $casa->setLatitude(33.5731);
        $casa->setLongitude(-7.5898);
        $em->persist($casa);

        $kech = new City();
        $kech->setName('Marrakech');
        $kech->setLatitude(31.6295);
        $kech->setLongitude(-7.9811);
        $em->persist($kech);

        $agadir = new City();
        $agadir->setName('Agadir');
        $agadir->setLatitude(30.4278);
        $agadir->setLongitude(-9.5981);
        $em->persist($agadir);

        // Driver
        $driver = new User();
        $driver->setEmail('driver@test.com');
        $driver->setFirstName('Karim');
        $driver->setLastName('Benali');
        $driver->addRole(User::ROLE_DRIVER);
        $driver->setPassword('pass');
        $driver->setRatingAvg(4.0);
        $driver->setRatingCount(10);
        $em->persist($driver);

        // Vehicle
        $vehicle = new Vehicle();
        $vehicle->setOwner($driver);
        $vehicle->setBrand('Dacia');
        $vehicle->setModel('Logan');
        $vehicle->setPlate('11111-A-1');
        $vehicle->setSeats(5);
        $em->persist($vehicle);

        // Trip: Casa (0km) -> Kech (240km) -> Agadir (500km)
        $trip = new Trip();
        $trip->setDriver($driver);
        $trip->setVehicle($vehicle);
        $trip->setTotalSeats(4);
        $trip->setStatus(Trip::STATUS_SCHEDULED);
        $depTime = new \DateTimeImmutable('+2 days 08:00');
        $trip->setDepartureTime($depTime);

        $wp0 = new Waypoint();
        $wp0->setTrip($trip);
        $wp0->setCity($casa);
        $wp0->setStopOrder(0);
        $wp0->setArrivalTime($depTime);
        $wp0->setDistanceFromOriginKm(0.0);
        $trip->addWaypoint($wp0);

        $wp1 = new Waypoint();
        $wp1->setTrip($trip);
        $wp1->setCity($kech);
        $wp1->setStopOrder(1);
        $wp1->setArrivalTime($depTime->modify('+3 hours'));
        $wp1->setDistanceFromOriginKm(240.0);
        $trip->addWaypoint($wp1);

        $wp2 = new Waypoint();
        $wp2->setTrip($trip);
        $wp2->setCity($agadir);
        $wp2->setStopOrder(2);
        $wp2->setArrivalTime($depTime->modify('+6 hours'));
        $wp2->setDistanceFromOriginKm(500.0);
        $trip->addWaypoint($wp2);

        $em->persist($trip);

        // Passenger
        $passenger = new User();
        $passenger->setEmail('pax@test.com');
        $passenger->setFirstName('Youssef');
        $passenger->setLastName('Alami');
        $passenger->setPassword('pass');
        $em->persist($passenger);

        $em->flush();

        return [$trip, $passenger, $casa, $kech, $agadir, $wp0, $wp1, $wp2];
    }

    public function testRegistrationCreatesUserWithDefaultRatingTier(): void
    {
        $client = static::createClient();

        $client->request('POST', '/register', [
            'email' => 'new.passenger@test.com',
            'firstName' => 'Ahmed',
            'lastName' => 'Rami',
            'phone' => '+212612345678',
            'password' => 'secret123',
            'accountType' => 'passenger',
        ]);

        $this->assertResponseRedirects('/login');

        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'new.passenger@test.com']);

        $this->assertNotNull($user);
        $this->assertEquals(4.0, $user->getRatingAvg());
        $this->assertSame(10, $user->getRatingCount());
        $this->assertFalse($user->isDriver());
    }

    public function testRegistrationAsDriverGrantsDriverRole(): void
    {
        $client = static::createClient();

        $client->request('POST', '/register', [
            'email' => 'new.driver@test.com',
            'firstName' => 'Hassan',
            'lastName' => 'Alami',
            'password' => 'secret123',
            'accountType' => 'driver',
        ]);

        $this->assertResponseRedirects('/login');

        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'new.driver@test.com']);

        $this->assertNotNull($user);
        $this->assertTrue($user->isDriver());
        $this->assertContains(User::ROLE_DRIVER, $user->getRoles());
    }

    public function testSearchAndBookingFlow(): void
    {
        $client = static::createClient();
        [$trip, $passenger, $casa, $kech, $agadir, $wp0, $wp1, $wp2] = $this->prepareTestData(static::getContainer());

        // Search from Casablanca to Marrakech
        $client->request('GET', '/search', [
            'origin' => $casa->getId(),
            'destination' => $kech->getId(),
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Casablanca');
        $this->assertSelectorTextContains('body', 'Marrakech');

        // Book 2 seats on this segment
        $client->loginUser($passenger);
        $client->request('POST', '/trips/' . $trip->getId() . '/book', [
            'board_waypoint_id' => $wp0->getId(),
            'alight_waypoint_id' => $wp1->getId(),
            'seats_count' => 2,
        ]);

        $this->assertResponseRedirects('/my-bookings');
        $client->followRedirect();

        $this->assertSelectorTextContains('body', 'Reservation confirmed');
        $this->assertSelectorTextContains('body', 'Casablanca');
        $this->assertSelectorTextContains('body', 'Marrakech');
    }
}
