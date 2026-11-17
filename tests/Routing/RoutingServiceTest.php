<?php

declare(strict_types=1);

namespace App\Tests\Routing;

use App\Routing\RoutingService;
use App\Routing\RoutingUnavailableException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class RoutingServiceTest extends TestCase
{
    private const API_KEY = 'test_secret_ors_key_123';
    private const BASE_URL = 'https://api.openrouteservice.org';

    public function testSuccessfulCumulativeEtasAccumulatesDistanceAndDuration(): void
    {
        $mockResponseBody = json_encode([
            'routes' => [
                [
                    'summary' => [
                        'distance' => 335000.0,
                        'duration' => 12600.0,
                    ],
                    'segments' => [
                        [
                            'distance' => 85400.0, // 85.4 km
                            'duration' => 3720.0,  // 62 min
                        ],
                        [
                            'distance' => 250100.0, // 250.1 km
                            'duration' => 9180.0,   // 153 min
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $capturedRequest = [];
        $mockResponse = new MockResponse($mockResponseBody, [
            'http_code' => 200,
            'response_headers' => ['content-type' => 'application/json'],
        ]);

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($mockResponse, &$capturedRequest) {
            $capturedRequest = [
                'method' => $method,
                'url' => $url,
                'options' => $options,
            ];
            return $mockResponse;
        });

        $cache = new ArrayAdapter();
        $service = new RoutingService($httpClient, $cache, self::API_KEY, self::BASE_URL);

        // 3 points: Casablanca, Rabat, Tangier
        $points = [
            ['lat' => 33.5731, 'lon' => -7.5898],
            ['lat' => 34.0209, 'lon' => -6.8416],
            ['lat' => 35.7595, 'lon' => -5.8340],
        ];

        $etas = $service->cumulativeEtas($points);

        // Verify captured HTTP request
        $this->assertSame('POST', $capturedRequest['method']);
        $this->assertSame('https://api.openrouteservice.org/v2/directions/driving-car', $capturedRequest['url']);

        // Check Authorization header (never logs or leaks key)
        $headers = $capturedRequest['options']['headers'] ?? [];
        $authHeader = null;
        foreach ($headers as $header) {
            if (str_starts_with($header, 'Authorization:')) {
                $authHeader = trim(substr($header, 14));
            }
        }
        $this->assertSame(self::API_KEY, $authHeader);

        // Verify JSON body coordinates are in [longitude, latitude] order
        $body = json_decode($capturedRequest['options']['body'] ?? '', true);
        $this->assertIsArray($body);
        $this->assertTrue($body['instructions']);
        $this->assertSame([
            [-7.5898, 33.5731],
            [-6.8416, 34.0209],
            [-5.8340, 35.7595],
        ], $body['coordinates']);

        // Verify StopEtas
        $this->assertCount(3, $etas);

        // Origin stop: always 0 km / 0 min
        $this->assertSame(0.0, $etas[0]->distanceKm);
        $this->assertSame(0, $etas[0]->durationMin);

        // Intermediate stop (Rabat): leg 1
        $this->assertSame(85.4, $etas[1]->distanceKm);
        $this->assertSame(62, $etas[1]->durationMin);

        // Destination stop (Tangier): leg 1 + leg 2
        // Distance: 85400 + 250100 = 335500 m -> 335.5 km
        // Duration: 3720 + 9180 = 12900 sec -> 215 min
        $this->assertSame(335.5, $etas[2]->distanceKm);
        $this->assertSame(215, $etas[2]->durationMin);
    }

    public function testResultsAreCachedForIdenticalCoordinates(): void
    {
        $mockResponseBody = json_encode([
            'routes' => [
                [
                    'segments' => [
                        ['distance' => 100000.0, 'duration' => 3600.0],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $callCount = 0;
        $httpClient = new MockHttpClient(function () use ($mockResponseBody, &$callCount) {
            $callCount++;
            return new MockResponse($mockResponseBody, ['http_code' => 200]);
        });

        $cache = new ArrayAdapter();
        $service = new RoutingService($httpClient, $cache, self::API_KEY, self::BASE_URL);

        $points = [
            ['lat' => 33.573111, 'lon' => -7.589811],
            ['lat' => 34.020911, 'lon' => -6.841611],
        ];

        // Call 1: hits API
        $etas1 = $service->cumulativeEtas($points);
        $this->assertSame(1, $callCount);

        // Call 2: identical coordinates -> hits cache, does not call API
        $etas2 = $service->cumulativeEtas($points);
        $this->assertSame(1, $callCount);
        $this->assertEquals($etas1, $etas2);
    }

    public function testFewerThanTwoPointsThrowsInvalidArgumentException(): void
    {
        $httpClient = new MockHttpClient();
        $cache = new ArrayAdapter();
        $service = new RoutingService($httpClient, $cache, self::API_KEY, self::BASE_URL);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('At least 2 points (origin and destination) are required.');

        $service->cumulativeEtas([
            ['lat' => 33.5731, 'lon' => -7.5898],
        ]);
    }

    public function testInvalidCoordinatesThrowsInvalidArgumentException(): void
    {
        $httpClient = new MockHttpClient();
        $cache = new ArrayAdapter();
        $service = new RoutingService($httpClient, $cache, self::API_KEY, self::BASE_URL);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Point at index 0 must contain numeric "lat" and "lon".');

        $service->cumulativeEtas([
            ['lat' => 'invalid', 'lon' => -7.5898],
            ['lat' => 34.0209, 'lon' => -6.8416],
        ]);
    }

    public function testHttp403QuotaExceededThrowsRoutingUnavailableExceptionAndIsNotCached(): void
    {
        $callCount = 0;
        $httpClient = new MockHttpClient(function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return new MockResponse('{"error": "Forbidden - quota exceeded"}', ['http_code' => 403]);
            }
            return new MockResponse(json_encode([
                'routes' => [
                    ['segments' => [['distance' => 50000.0, 'duration' => 1800.0]]],
                ],
            ]), ['http_code' => 200]);
        });

        $cache = new ArrayAdapter();
        $service = new RoutingService($httpClient, $cache, self::API_KEY, self::BASE_URL);

        $points = [
            ['lat' => 31.6295, 'lon' => -7.9811],
            ['lat' => 30.4278, 'lon' => -9.5981],
        ];

        // Call 1: HTTP 403 -> throws exception
        try {
            $service->cumulativeEtas($points);
            $this->fail('Expected RoutingUnavailableException was not thrown.');
        } catch (RoutingUnavailableException $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertStringContainsString('quota exceeded', $e->getMessage());
        }

        // Call 2: retry should hit the API because failures are NOT cached
        $etas = $service->cumulativeEtas($points);
        $this->assertSame(2, $callCount);
        $this->assertCount(2, $etas);
        $this->assertSame(50.0, $etas[1]->distanceKm);
    }

    public function testHttp429RateLimitThrowsRoutingUnavailableException(): void
    {
        $httpClient = new MockHttpClient(new MockResponse('{"error": "Too Many Requests"}', ['http_code' => 429]));
        $cache = new ArrayAdapter();
        $service = new RoutingService($httpClient, $cache, self::API_KEY, self::BASE_URL);

        $points = [
            ['lat' => 33.5731, 'lon' => -7.5898],
            ['lat' => 34.0209, 'lon' => -6.8416],
        ];

        $this->expectException(RoutingUnavailableException::class);
        $this->expectExceptionCode(429);
        $this->expectExceptionMessage('rate limit exceeded');

        $service->cumulativeEtas($points);
    }

    public function testHttp500ServerErrorThrowsRoutingUnavailableException(): void
    {
        $httpClient = new MockHttpClient(new MockResponse('Internal Server Error', ['http_code' => 500]));
        $cache = new ArrayAdapter();
        $service = new RoutingService($httpClient, $cache, self::API_KEY, self::BASE_URL);

        $points = [
            ['lat' => 33.5731, 'lon' => -7.5898],
            ['lat' => 34.0209, 'lon' => -6.8416],
        ];

        $this->expectException(RoutingUnavailableException::class);
        $this->expectExceptionCode(500);
        $this->expectExceptionMessage('server error');

        $service->cumulativeEtas($points);
    }

    public function testInvalidResponseStructureThrowsRoutingUnavailableException(): void
    {
        $httpClient = new MockHttpClient(new MockResponse(json_encode(['routes' => []]), ['http_code' => 200]));
        $cache = new ArrayAdapter();
        $service = new RoutingService($httpClient, $cache, self::API_KEY, self::BASE_URL);

        $points = [
            ['lat' => 33.5731, 'lon' => -7.5898],
            ['lat' => 34.0209, 'lon' => -6.8416],
        ];

        $this->expectException(RoutingUnavailableException::class);
        $this->expectExceptionMessage('Invalid response structure received');

        $service->cumulativeEtas($points);
    }

    public function testSegmentsMismatchThrowsRoutingUnavailableException(): void
    {
        // 3 points expects 2 segments, but API returns 1
        $httpClient = new MockHttpClient(new MockResponse(json_encode([
            'routes' => [
                ['segments' => [['distance' => 50000.0, 'duration' => 1800.0]]],
            ],
        ]), ['http_code' => 200]));

        $cache = new ArrayAdapter();
        $service = new RoutingService($httpClient, $cache, self::API_KEY, self::BASE_URL);

        $points = [
            ['lat' => 33.5731, 'lon' => -7.5898],
            ['lat' => 34.0209, 'lon' => -6.8416],
            ['lat' => 35.7595, 'lon' => -5.8340],
        ];

        $this->expectException(RoutingUnavailableException::class);
        $this->expectExceptionMessage('Mismatch between requested legs (2) and returned route segments (1)');

        $service->cumulativeEtas($points);
    }

    public function testComputeRouteExtractsAndDecodesPolylineGeometry(): void
    {
        $mockResponseBody = json_encode([
            'routes' => [
                [
                    'geometry' => '{f|kEfkim@wmvAgcqC',
                    'segments' => [
                        ['distance' => 85000.0, 'duration' => 3600.0],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $httpClient = new MockHttpClient(new MockResponse($mockResponseBody, ['http_code' => 200]));
        $cache = new ArrayAdapter();
        $service = new RoutingService($httpClient, $cache, self::API_KEY, self::BASE_URL);

        $points = [
            ['lat' => 33.5731, 'lon' => -7.5898],
            ['lat' => 34.0209, 'lon' => -6.8416],
        ];

        $result = $service->computeRoute($points);

        $this->assertCount(2, $result->etas);
        $this->assertNotNull($result->geometry);
        $this->assertSame('LineString', $result->geometry['type']);
        $this->assertSame([[-7.5898, 33.5731], [-6.8416, 34.0209]], $result->geometry['coordinates']);
    }

    public function testDecodePolyline(): void
    {
        $httpClient = new MockHttpClient();
        $cache = new ArrayAdapter();
        $service = new RoutingService($httpClient, $cache, self::API_KEY, self::BASE_URL);

        // Encoded string for Casablanca -> Rabat
        $coords = $service->decodePolyline('{f|kEfkim@wmvAgcqC');

        $this->assertCount(2, $coords);
        $this->assertEquals(-7.5898, $coords[0][0]);
        $this->assertEquals(33.5731, $coords[0][1]);
        $this->assertEquals(-6.8416, $coords[1][0]);
        $this->assertEquals(34.0209, $coords[1][1]);
    }

    public function testGeoJsonFeatureCollectionFormatIsSupported(): void
    {
        $mockResponseBody = json_encode([
            'type' => 'FeatureCollection',
            'features' => [
                [
                    'type' => 'Feature',
                    'geometry' => [
                        'type' => 'LineString',
                        'coordinates' => [[-7.5898, 33.5731], [-6.8416, 34.0209]],
                    ],
                    'properties' => [
                        'segments' => [
                            ['distance' => 85000.0, 'duration' => 3600.0],
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $httpClient = new MockHttpClient(new MockResponse($mockResponseBody, ['http_code' => 200]));
        $cache = new ArrayAdapter();
        $service = new RoutingService($httpClient, $cache, self::API_KEY, self::BASE_URL);

        $points = [
            ['lat' => 33.5731, 'lon' => -7.5898],
            ['lat' => 34.0209, 'lon' => -6.8416],
        ];

        $result = $service->computeRoute($points);

        $this->assertCount(2, $result->etas);
        $this->assertSame(85.0, $result->etas[1]->distanceKm);
        $this->assertSame(60, $result->etas[1]->durationMin);
        $this->assertSame([[-7.5898, 33.5731], [-6.8416, 34.0209]], $result->geometry['coordinates']);
    }

    public function testTwoPointsFallbackToSummaryWhenSegmentsOmitted(): void
    {
        $mockResponseBody = json_encode([
            'routes' => [
                [
                    'summary' => [
                        'distance' => 464000.0,
                        'duration' => 16700.0,
                    ],
                    'geometry' => '{f|kEfkim@wmvAgcqC',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $httpClient = new MockHttpClient(new MockResponse($mockResponseBody, ['http_code' => 200]));
        $cache = new ArrayAdapter();
        $service = new RoutingService($httpClient, $cache, self::API_KEY, self::BASE_URL);

        $points = [
            ['lat' => 30.4278, 'lon' => -9.5981],
            ['lat' => 33.5731, 'lon' => -7.5898],
        ];

        $result = $service->computeRoute($points);

        $this->assertCount(2, $result->etas);
        $this->assertSame(464.0, $result->etas[1]->distanceKm);
        $this->assertSame(278, $result->etas[1]->durationMin);
    }
}
