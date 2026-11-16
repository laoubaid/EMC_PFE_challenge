<?php

namespace App\Tests\Security;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class SecurityAccessTest extends WebTestCase
{
    private function prepareDatabase($container): array
    {
        $em = $container->get('doctrine.orm.entity_manager');
        $schemaTool = new \Doctrine\ORM\Tools\SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        // Create Admin
        $admin = new User();
        $admin->setEmail('admin@test.com');
        $admin->setFirstName('Admin');
        $admin->setLastName('Test');
        $admin->setIsAdmin(true);
        $admin->setPassword('hashed');
        $em->persist($admin);

        // Create Driver
        $driver = new User();
        $driver->setEmail('driver@test.com');
        $driver->setFirstName('Driver');
        $driver->setLastName('Test');
        $driver->addRole(User::ROLE_DRIVER);
        $driver->setPassword('hashed');
        $em->persist($driver);

        // Create Passenger
        $passenger = new User();
        $passenger->setEmail('passenger@test.com');
        $passenger->setFirstName('Passenger');
        $passenger->setLastName('Test');
        $passenger->setPassword('hashed');
        $em->persist($passenger);

        $em->flush();

        return [$admin, $driver, $passenger];
    }

    public function testAnonymousAccessToAdminRedirectsToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin');

        $this->assertResponseRedirects('/login');
    }

    public function testAnonymousAccessToDriverRedirectsToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/driver');

        $this->assertResponseRedirects('/login');
    }

    public function testPassengerAccessToAdminIsForbidden(): void
    {
        $client = static::createClient();
        [$admin, $driver, $passenger] = $this->prepareDatabase(static::getContainer());

        $client->loginUser($passenger);
        $client->request('GET', '/admin');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testPassengerAccessToDriverIsForbidden(): void
    {
        $client = static::createClient();
        [$admin, $driver, $passenger] = $this->prepareDatabase(static::getContainer());

        $client->loginUser($passenger);
        $client->request('GET', '/driver');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminCanAccessAdminDashboard(): void
    {
        $client = static::createClient();
        [$admin, $driver, $passenger] = $this->prepareDatabase(static::getContainer());

        $client->loginUser($admin);
        $client->request('GET', '/admin');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Platform Dashboard');
    }

    public function testDriverCanAccessDriverDashboard(): void
    {
        $client = static::createClient();
        [$admin, $driver, $passenger] = $this->prepareDatabase(static::getContainer());

        $client->loginUser($driver);
        $client->request('GET', '/driver');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Driver Workspace');
    }

    public function testPassengerCanAccessMyBookings(): void
    {
        $client = static::createClient();
        [$admin, $driver, $passenger] = $this->prepareDatabase(static::getContainer());

        $client->loginUser($passenger);
        $client->request('GET', '/my-bookings');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'My Reservations');
    }
}
