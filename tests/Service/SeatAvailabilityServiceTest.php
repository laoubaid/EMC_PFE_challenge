<?php

namespace App\Tests\Service;

use App\Entity\Booking;
use App\Entity\City;
use App\Entity\Trip;
use App\Entity\User;
use App\Entity\Waypoint;
use App\Service\SeatAvailabilityService;
use PHPUnit\Framework\TestCase;

class SeatAvailabilityServiceTest extends TestCase
{
    private SeatAvailabilityService $service;

    protected function setUp(): void
    {
        $this->service = new SeatAvailabilityService();
    }

    private function createTripWithWaypoints(int $totalSeats, array $cityNames): array
    {
        $trip = new Trip();
        $trip->setTotalSeats($totalSeats);

        $waypoints = [];
        foreach ($cityNames as $order => $cityName) {
            $city = new City();
            $city->setName($cityName);

            $wp = new Waypoint();
            $wp->setCity($city);
            $wp->setStopOrder($order);
            $wp->setArrivalTime(new \DateTimeImmutable(sprintf('+%d hours', $order * 2)));
            $wp->setDistanceFromOriginKm($order * 100);
            $trip->addWaypoint($wp);
            $waypoints[$order] = $wp;
        }

        return [$trip, $waypoints];
    }

    public function testSeatFreedAtIntermediateStopCanBeResold(): void
    {
        // Route: Casablanca (0) -> Marrakech (1) -> Agadir (2). Capacity = 4 seats.
        [$trip, $wp] = $this->createTripWithWaypoints(4, ['Casablanca', 'Marrakech', 'Agadir']);

        // Passenger 1 books 1 seat from Casablanca (0) to Marrakech (1)
        $booking1 = new Booking();
        $booking1->setTrip($trip);
        $booking1->setBoardWaypoint($wp[0]);
        $booking1->setAlightWaypoint($wp[1]);
        $booking1->setSeatsCount(1);
        $booking1->setStatus(Booking::STATUS_CONFIRMED);
        $trip->getBookings()->add($booking1);

        // On Casablanca -> Marrakech (Leg 0): 1 seat occupied => 3 seats available
        $availCasablancaMarrakech = $this->service->getAvailableSeatsForSegment($trip, $wp[0], $wp[1]);
        $this->assertSame(3, $availCasablancaMarrakech);

        // On Marrakech -> Agadir (Leg 1): 0 seats occupied => all 4 seats are available!
        // The seat freed at Marrakech can be resold for the remainder of the trip!
        $availMarrakechAgadir = $this->service->getAvailableSeatsForSegment($trip, $wp[1], $wp[2]);
        $this->assertSame(4, $availMarrakechAgadir);

        // For whole trip Casablanca -> Agadir (Leg 0 + Leg 1): limited by min(3, 4) = 3 seats
        $availFullTrip = $this->service->getAvailableSeatsForSegment($trip, $wp[0], $wp[2]);
        $this->assertSame(3, $availFullTrip);
    }

    public function testComplexOverlappingSegmentsAcrossFourStops(): void
    {
        // Route: Tangier (0) -> Rabat (1) -> Casablanca (2) -> Marrakech (3). Capacity = 3 seats.
        [$trip, $wp] = $this->createTripWithWaypoints(3, ['Tangier', 'Rabat', 'Casablanca', 'Marrakech']);

        // Booking 1: Tangier (0) -> Casablanca (2) [2 seats]
        $b1 = new Booking();
        $b1->setTrip($trip);
        $b1->setBoardWaypoint($wp[0]);
        $b1->setAlightWaypoint($wp[2]);
        $b1->setSeatsCount(2);
        $b1->setStatus(Booking::STATUS_CONFIRMED);
        $trip->getBookings()->add($b1);

        // Booking 2: Rabat (1) -> Marrakech (3) [1 seat]
        $b2 = new Booking();
        $b2->setTrip($trip);
        $b2->setBoardWaypoint($wp[1]);
        $b2->setAlightWaypoint($wp[3]);
        $b2->setSeatsCount(1);
        $b2->setStatus(Booking::STATUS_CONFIRMED);
        $trip->getBookings()->add($b2);

        // Breakdown:
        // Leg 0 (Tangier -> Rabat): 2 occupied => 1 available
        // Leg 1 (Rabat -> Casablanca): 2 + 1 = 3 occupied => 0 available (FULL)
        // Leg 2 (Casablanca -> Marrakech): 1 occupied => 2 available

        $this->assertSame(1, $this->service->getAvailableSeatsForSegment($trip, $wp[0], $wp[1]));
        $this->assertSame(0, $this->service->getAvailableSeatsForSegment($trip, $wp[1], $wp[2]));
        $this->assertSame(2, $this->service->getAvailableSeatsForSegment($trip, $wp[2], $wp[3]));

        // Passenger wanting Casablanca -> Marrakech (2 seats) => SUCCESS!
        $this->assertTrue($this->service->isSegmentAvailable($trip, $wp[2], $wp[3], 2));

        // Passenger wanting Rabat -> Marrakech (1 seat) => FAILS because Leg 1 is full!
        $this->assertFalse($this->service->isSegmentAvailable($trip, $wp[1], $wp[3], 1));
    }

    public function testCancelledBookingDoesNotBlockSeats(): void
    {
        [$trip, $wp] = $this->createTripWithWaypoints(4, ['Casablanca', 'Marrakech']);

        $cancelledBooking = new Booking();
        $cancelledBooking->setTrip($trip);
        $cancelledBooking->setBoardWaypoint($wp[0]);
        $cancelledBooking->setAlightWaypoint($wp[1]);
        $cancelledBooking->setSeatsCount(4);
        $cancelledBooking->setStatus(Booking::STATUS_CANCELLED_PASSENGER);
        $trip->getBookings()->add($cancelledBooking);

        // Since it's cancelled, all 4 seats are available
        $this->assertSame(4, $this->service->getAvailableSeatsForSegment($trip, $wp[0], $wp[1]));
        $this->assertTrue($this->service->isSegmentAvailable($trip, $wp[0], $wp[1], 4));
    }
}
