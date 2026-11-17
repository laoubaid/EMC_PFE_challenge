<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Entity\Waypoint;
use App\Twig\RoutingExtension;
use PHPUnit\Framework\TestCase;

class RoutingExtensionTest extends TestCase
{
    private RoutingExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new RoutingExtension();
    }

    public function testDisplayStopEtaForOrigin(): void
    {
        $wp = new Waypoint();
        $wp->setStopOrder(0);
        $wp->setDistanceFromOriginKm(0.0);

        $this->assertSame('0 km (Departure)', $this->extension->displayStopEta($wp));
    }

    public function testDisplayStopEtaForIntermediateAndDestination(): void
    {
        $wp = new Waypoint();
        $wp->setStopOrder(2); // 1 intermediate stop before this
        $wp->setDistanceFromOriginKm(100.0);
        $wp->setDurationFromOriginMin(60); // 60 * 1.10 = 66 + 5 = 71 min

        // 71 min = ~1 h 11 min
        $this->assertSame('~1 h 11 min · 100.0 km (approx.)', $this->extension->displayStopEta($wp));
    }

    public function testDisplaySegmentEta(): void
    {
        $wpFrom = new Waypoint();
        $wpFrom->setStopOrder(1);
        $wpFrom->setDistanceFromOriginKm(50.0);
        $wpFrom->setDurationFromOriginMin(30);

        $wpTo = new Waypoint();
        $wpTo->setStopOrder(3); // 1 intermediate stop between order 1 and 3
        $wpTo->setDistanceFromOriginKm(200.0);
        $wpTo->setDurationFromOriginMin(120);

        // Subtraction: distance = 150 km, duration = 90 min
        // Buffered (1 intermediate stop): 90 * 1.10 = 99 + 5 = 104 min (~1 h 44 min)
        $this->assertSame('~1 h 44 min · 150.0 km (approx.)', $this->extension->displaySegmentEta($wpFrom, $wpTo));
    }

    public function testTripSegmentReturnsStopEtaDto(): void
    {
        $wpFrom = new Waypoint();
        $wpFrom->setDistanceFromOriginKm(50.0);
        $wpFrom->setDurationFromOriginMin(30);

        $wpTo = new Waypoint();
        $wpTo->setDistanceFromOriginKm(170.5);
        $wpTo->setDurationFromOriginMin(105);

        $eta = $this->extension->tripSegment($wpFrom, $wpTo);
        $this->assertSame(120.5, $eta->distanceKm);
        $this->assertSame(75, $eta->durationMin);
    }

    public function testFormatDuration(): void
    {
        $this->assertSame('~0 min', $this->extension->formatDuration(0));
        $this->assertSame('~50 min', $this->extension->formatDuration(50));
        $this->assertSame('~2 h', $this->extension->formatDuration(120));
        $this->assertSame('~2 h 30 min', $this->extension->formatDuration(150));
    }
}
