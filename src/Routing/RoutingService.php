<?php

declare(strict_types=1);

namespace App\Routing;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class RoutingService
{
    private const CACHE_TTL_SECONDS = 30 * 86400; // 30 days
    private const TIMEOUT_SECONDS = 10.0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        #[Autowire('%env(ORS_API_KEY)%')]
        private readonly string $apiKey,
        #[Autowire('%env(ORS_BASE_URL)%')]
        private readonly string $baseUrl = 'https://api.openrouteservice.org',
        private readonly LoggerInterface $logger = new NullLogger()
    ) {
    }

    /**
     * Computes cumulative distance, duration and route geometry (GeoJSON LineString) from trip start for every stop.
     *
     * @param list<array{lat: float, lon: float}> $points Ordered [origin, ...stops, destination]
     * @return RouteResult Contains list of StopEta (index 0 is always 0.0 km / 0 min) and GeoJSON LineString
     *
     * @throws \InvalidArgumentException If fewer than 2 points are provided
     * @throws RoutingUnavailableException If ORS API returns an error or is unreachable
     */
    public function computeRoute(array $points): RouteResult
    {
        if (count($points) < 2) {
            throw new \InvalidArgumentException('At least 2 points (origin and destination) are required.');
        }

        // Validate structure and coordinates
        foreach ($points as $idx => $point) {
            if (!isset($point['lat'], $point['lon']) || !is_numeric($point['lat']) || !is_numeric($point['lon'])) {
                throw new \InvalidArgumentException(sprintf('Point at index %d must contain numeric "lat" and "lon".', $idx));
            }
        }

        // Build cache key based on md5 of rounded coordinates (4 decimal places ~ 11m accuracy)
        $coordStrings = [];
        foreach ($points as $point) {
            $coordStrings[] = sprintf('%.4f,%.4f', (float) $point['lat'], (float) $point['lon']);
        }
        $cacheKey = 'ors_route_v2_' . md5(implode('|', $coordStrings));

        $cachedData = $this->cache->get($cacheKey, function (ItemInterface $item) use ($points): array {
            $item->expiresAfter(self::CACHE_TTL_SECONDS);

            $result = $this->fetchFromOrs($points);

            // Serialize StopEta objects for cache storage
            $etasArray = [];
            foreach ($result->etas as $eta) {
                $etasArray[] = [
                    'distanceKm' => $eta->distanceKm,
                    'durationMin' => $eta->durationMin,
                ];
            }

            return [
                'etas' => $etasArray,
                'geometry' => $result->geometry,
            ];
        });

        $etas = [];
        foreach ($cachedData['etas'] as $item) {
            $etas[] = new StopEta(
                distanceKm: (float) $item['distanceKm'],
                durationMin: (int) $item['durationMin']
            );
        }

        return new RouteResult(
            etas: $etas,
            geometry: $cachedData['geometry'] ?? null
        );
    }

    /**
     * Backward-compatible helper returning only cumulative ETAs list.
     *
     * @param list<array{lat: float, lon: float}> $points
     * @return list<StopEta>
     */
    public function cumulativeEtas(array $points): array
    {
        return $this->computeRoute($points)->etas;
    }

    /**
     * @param list<array{lat: float, lon: float}> $points
     * @return RouteResult
     */
    private function fetchFromOrs(array $points): RouteResult
    {
        // ORS expects coordinates in [longitude, latitude] order
        $orsCoordinates = [];
        foreach ($points as $point) {
            $orsCoordinates[] = [(float) $point['lon'], (float) $point['lat']];
        }

        $url = rtrim($this->baseUrl, '/') . '/v2/directions/driving-car';

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Authorization' => $this->apiKey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'json' => [
                    'coordinates' => $orsCoordinates,
                    'instructions' => true,
                ],
                'timeout' => self::TIMEOUT_SECONDS,
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode === 403) {
                throw new RoutingUnavailableException('OpenRouteService quota exceeded or forbidden (HTTP 403).', 403);
            }

            if ($statusCode === 429) {
                throw new RoutingUnavailableException('OpenRouteService rate limit exceeded (HTTP 429).', 429);
            }

            if ($statusCode >= 500) {
                throw new RoutingUnavailableException(sprintf('OpenRouteService server error (HTTP %d).', $statusCode), $statusCode);
            }

            if ($statusCode !== 200) {
                throw new RoutingUnavailableException(sprintf('OpenRouteService returned unexpected status HTTP %d.', $statusCode), $statusCode);
            }

            $content = $response->toArray(false);
        } catch (RoutingUnavailableException $e) {
            // Re-throw custom domain exception (never logs API key)
            $this->logger->warning('OpenRouteService routing unavailable: ' . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->warning('OpenRouteService request failed: ' . $e->getMessage());
            throw new RoutingUnavailableException('OpenRouteService request failed: ' . $e->getMessage(), 0, $e);
        }

        // Extract route object: supports both standard JSON (routes[0]) and GeoJSON (features[0])
        $route = null;
        $rawGeometry = null;

        if (isset($content['routes'][0]) && is_array($content['routes'][0])) {
            $route = $content['routes'][0];
            $rawGeometry = $route['geometry'] ?? null;
        } elseif (isset($content['features'][0]['properties']) && is_array($content['features'][0]['properties'])) {
            $route = $content['features'][0]['properties'];
            $rawGeometry = $content['features'][0]['geometry'] ?? null;
        }

        if ($route === null) {
            $this->logger->error('OpenRouteService response missing routes or features: ' . json_encode($content));
            throw new RoutingUnavailableException('Invalid response structure received from OpenRouteService directions API.');
        }

        // Extract segments: each segment is one leg between consecutive stops
        $segments = null;
        if (isset($route['segments']) && is_array($route['segments']) && !empty($route['segments'])) {
            $segments = $route['segments'];
        } elseif (count($points) === 2 && isset($route['summary']) && is_array($route['summary'])) {
            // For a single leg between 2 points, fallback to summary if segments is omitted
            $segments = [$route['summary']];
        }

        if ($segments === null) {
            $this->logger->error('OpenRouteService response missing segments: ' . json_encode($content));
            throw new RoutingUnavailableException('Invalid response structure received from OpenRouteService directions API.');
        }

        $expectedSegmentsCount = count($points) - 1;

        if (count($segments) !== $expectedSegmentsCount) {
            throw new RoutingUnavailableException(sprintf(
                'Mismatch between requested legs (%d) and returned route segments (%d).',
                $expectedSegmentsCount,
                count($segments)
            ));
        }

        /** @var list<StopEta> $etas */
        $etas = [];
        // Index 0 is always 0 km / 0 min
        $etas[] = new StopEta(distanceKm: 0.0, durationMin: 0);

        $cumulativeMeters = 0.0;
        $cumulativeSeconds = 0.0;

        foreach ($segments as $segment) {
            $distanceMeters = (float) ($segment['distance'] ?? 0.0);
            $durationSeconds = (float) ($segment['duration'] ?? 0.0);

            $cumulativeMeters += $distanceMeters;
            $cumulativeSeconds += $durationSeconds;

            $etas[] = new StopEta(
                distanceKm: round($cumulativeMeters / 1000.0, 1),
                durationMin: (int) round($cumulativeSeconds / 60.0)
            );
        }

        // Extract geometry (GeoJSON LineString)
        $lineStringCoordinates = [];

        if ($rawGeometry !== null) {
            if (is_string($rawGeometry)) {
                // Decode Google Encoded Polyline into [lon, lat] pairs
                $lineStringCoordinates = $this->decodePolyline($rawGeometry);
            } elseif (is_array($rawGeometry)) {
                if (isset($rawGeometry['coordinates']) && is_array($rawGeometry['coordinates'])) {
                    $lineStringCoordinates = $rawGeometry['coordinates'];
                } elseif (isset($rawGeometry[0]) && is_array($rawGeometry[0])) {
                    $lineStringCoordinates = $rawGeometry;
                }
            }
        }

        // Fallback: if no geometry from ORS, use straight line between points
        if (empty($lineStringCoordinates)) {
            $lineStringCoordinates = $orsCoordinates;
        }

        $geometry = [
            'type' => 'LineString',
            'coordinates' => $lineStringCoordinates,
        ];

        return new RouteResult(etas: $etas, geometry: $geometry);
    }

    /**
     * Decodes a Google Encoded Polyline string into a list of [lon, lat] coordinates (GeoJSON order).
     *
     * @return list<array{0: float, 1: float}>
     */
    public function decodePolyline(string $encoded): array
    {
        $length = strlen($encoded);
        $index = 0;
        $points = [];
        $lat = 0;
        $lng = 0;

        while ($index < $length) {
            $shift = 0;
            $result = 0;
            do {
                $b = ord($encoded[$index++]) - 63;
                $result |= ($b & 0x1f) << $shift;
                $shift += 5;
            } while ($b >= 0x20 && $index < $length);

            $dlat = (($result & 1) ? ~($result >> 1) : ($result >> 1));
            $lat += $dlat;

            $shift = 0;
            $result = 0;
            do {
                if ($index >= $length) {
                    break;
                }
                $b = ord($encoded[$index++]) - 63;
                $result |= ($b & 0x1f) << $shift;
                $shift += 5;
            } while ($b >= 0x20 && $index < $length);

            $dlng = (($result & 1) ? ~($result >> 1) : ($result >> 1));
            $lng += $dlng;

            // Output in [longitude, latitude] order as standard for GeoJSON
            $points[] = [
                round($lng * 1e-5, 5),
                round($lat * 1e-5, 5),
            ];
        }

        return $points;
    }
}
