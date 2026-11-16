<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AdminControllerTest extends WebTestCase
{
    private function createAdminUser($container): User
    {
        $em = $container->get('doctrine.orm.entity_manager');
        $schemaTool = new \Doctrine\ORM\Tools\SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $admin = new User();
        $admin->setEmail('admin@test.com');
        $admin->setFirstName('Admin');
        $admin->setLastName('Platform');
        $admin->setIsAdmin(true);
        $admin->setPassword('password');
        $em->persist($admin);
        $em->flush();

        return $admin;
    }

    public function testAdminDashboardIsAccessible(): void
    {
        $client = static::createClient();
        $admin = $this->createAdminUser(static::getContainer());
        $client->loginUser($admin);

        $client->request('GET', '/admin');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Platform Dashboard');
    }

    public function testAdminTripsPageIsAccessible(): void
    {
        $client = static::createClient();
        $admin = $this->createAdminUser(static::getContainer());
        $client->loginUser($admin);

        $client->request('GET', '/admin/trips');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Trips & Intermediate Stops');
    }

    public function testAdminPricingPageIsAccessible(): void
    {
        $client = static::createClient();
        $admin = $this->createAdminUser(static::getContainer());
        $client->loginUser($admin);

        $client->request('GET', '/admin/pricing');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Pricing Engine Configuration');
    }
}
