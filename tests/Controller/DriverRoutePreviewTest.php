<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\City;
use App\Entity\User;
use App\Routing\RouteResult;
use App\Routing\RoutingService;
use App\Routing\StopEta;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class DriverRoutePreviewTest extends WebTestCase
{
    public function testRoutePreviewReturnsExpectedJson(): void
    {
        $client = static::createClient();
        $container = static::getContainer();

        $em = $container->get('doctrine.orm.entity_manager');
        $schemaTool = new \Doctrine\ORM\Tools\SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $driver = new User();
        $driver->setEmail('driver_preview@test.com');
        $driver->setFirstName('Driver');
        $driver->setLastName('Preview');
        $driver->addRole(User::ROLE_DRIVER);
        $driver->setPassword('hash');
        $em->persist($driver);

        $casa = new City();
        $casa->setName('Casablanca');
        $casa->setLatitude(33.5731);
        $casa->setLongitude(-7.5898);
        $em->persist($casa);

        $rabat = new City();
        $rabat->setName('Rabat');
        $rabat->setLatitude(34.0209);
        $rabat->setLongitude(-6.8416);
        $em->persist($rabat);

        $em->flush();

        // Mock RoutingService
        $mockRouting = $this->createMock(RoutingService::class);
        $mockRouting->expects($this->once())
            ->method('computeRoute')
            ->willReturn(new RouteResult(
                etas: [
                    new StopEta(0.0, 0),
                    new StopEta(85.4, 62),
                ],
                geometry: [
                    'type' => 'LineString',
                    'coordinates' => [[-7.5898, 33.5731], [-6.8416, 34.0209]],
                ]
            ));

        $container->set(RoutingService::class, $mockRouting);

        $client->loginUser($driver);

        $client->request('POST', '/driver/route-preview', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], json_encode([
            'origin_city_id' => $casa->getId(),
            'dest_city_id' => $rabat->getId(),
        ]));

        $this->assertResponseIsSuccessful();
        $responseContent = json_decode($client->getResponse()->getContent(), true);

        $this->assertTrue($responseContent['success']);
        $this->assertSame(85.4, $responseContent['totalDistanceKm']);
        $this->assertSame(62, $responseContent['totalDurationMin']);
        $this->assertCount(2, $responseContent['stops']);
        $this->assertSame('LineString', $responseContent['geometry']['type']);
    }

    public function testRoutePreviewFailsOnMissingOrigin(): void
    {
        $client = static::createClient();
        $container = static::getContainer();

        $em = $container->get('doctrine.orm.entity_manager');
        $schemaTool = new \Doctrine\ORM\Tools\SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $driver = new User();
        $driver->setEmail('driver_preview2@test.com');
        $driver->setFirstName('Driver');
        $driver->setLastName('Preview');
        $driver->addRole(User::ROLE_DRIVER);
        $driver->setPassword('hash');
        $em->persist($driver);
        $em->flush();

        $client->loginUser($driver);

        $client->request('POST', '/driver/route-preview', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'origin_city_id' => 0,
            'dest_city_id' => 1,
        ]));

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertFalse($data['success']);
    }
}
