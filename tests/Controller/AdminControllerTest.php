<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AdminControllerTest extends WebTestCase
{
    public function testAdminDashboardIsAccessible(): void
    {
        $client = static::createClient();

        // Create test schema and seed
        $container = static::getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        $schemaTool = new \Doctrine\ORM\Tools\SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $client->request('GET', '/admin');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Platform Dashboard');
    }

    public function testAdminTripsPageIsAccessible(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/trips');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Trips & Intermediate Stops');
    }

    public function testAdminPricingPageIsAccessible(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/pricing');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Pricing Engine Configuration');
    }
}
