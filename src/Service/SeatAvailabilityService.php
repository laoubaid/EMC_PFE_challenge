<?php

namespace App\Service;

use App\Entity\Booking;
use App\Entity\Trip;
use App\Entity\Waypoint;
use App\Repository\BookingRepository;

class SeatAvailabilityService
{
    public function __construct(
        private readonly ?BookingRepository $bookingRepository = null
    ) {
    }

    /**
     * Calculates the minimum available seats on all contiguous legs between board and alight waypoints.
     * Adheres to DECISIONS.md: a seat freed at an intermediate stop is available for subsequent stops!
     */
    public function getAvailableSeatsForSegment(Trip $trip, Waypoint $board, Waypoint $alight): int
    {
        $startOrder = $board->getStopOrder();
        $endOrder = $alight->getStopOrder();

        if ($startOrder >= $endOrder) {
            return 0;
        }

        $totalSeats = $trip->getTotalSeats() ?? 0;
        if ($totalSeats <= 0) {
            return 0;
        }

        // Retrieve active bookings for the trip
        $activeBookings = $this->bookingRepository
            ? $this->bookingRepository->findActiveBookingsForTrip($trip)
            : $trip->getBookings()->filter(static fn(Booking $b) => $b->isActive())->toArray();

        // Calculate occupancy on each elementary leg k in [startOrder, endOrder - 1]
        $minAvailable = $totalSeats;

        for ($legIndex = $startOrder; $legIndex < $endOrder; $legIndex++) {
            $occupiedOnLeg = 0;

            foreach ($activeBookings as $booking) {
                $bStart = $booking->getBoardWaypoint()?->getStopOrder();
                $bEnd = $booking->getAlightWaypoint()?->getStopOrder();

                if ($bStart !== null && $bEnd !== null) {
                    // Booking occupies this leg if bStart <= legIndex < bEnd
                    if ($bStart <= $legIndex && $legIndex < $bEnd) {
                        $occupiedOnLeg += $booking->getSeatsCount() ?? 1;
                    }
                }
            }

            $availableOnLeg = max(0, $totalSeats - $occupiedOnLeg);
            if ($availableOnLeg < $minAvailable) {
                $minAvailable = $availableOnLeg;
            }

            // Early exit if any intermediate leg is full
            if ($minAvailable === 0) {
                return 0;
            }
        }

        return $minAvailable;
    }

    /**
     * Checks if requested seats can be booked on the specified segment without causing overbooking.
     */
    public function isSegmentAvailable(Trip $trip, Waypoint $board, Waypoint $alight, int $requestedSeats = 1): bool
    {
        if ($requestedSeats <= 0) {
            return false;
        }

        return $this->getAvailableSeatsForSegment($trip, $board, $alight) >= $requestedSeats;
    }

    /**
     * Returns a breakdown of occupancy and remaining seats for each leg of the trip.
     *
     * @return list<array{
     *     legIndex: int,
     *     fromCity: string,
     *     toCity: string,
     *     fromOrder: int,
     *     toOrder: int,
     *     totalSeats: int,
     *     occupiedSeats: int,
     *     availableSeats: int
     * }>
     */
    public function getLegOccupancyBreakdown(Trip $trip): array
    {
        $orderedWaypoints = $trip->getOrderedWaypoints();
        $totalSeats = $trip->getTotalSeats() ?? 0;
        $breakdown = [];

        $activeBookings = $this->bookingRepository
            ? $this->bookingRepository->findActiveBookingsForTrip($trip)
            : $trip->getBookings()->filter(static fn(Booking $b) => $b->isActive())->toArray();

        for ($i = 0; $i < count($orderedWaypoints) - 1; $i++) {
            $from = $orderedWaypoints[$i];
            $to = $orderedWaypoints[$i + 1];
            $legIndex = $from->getStopOrder();

            $occupied = 0;
            foreach ($activeBookings as $booking) {
                $bStart = $booking->getBoardWaypoint()?->getStopOrder();
                $bEnd = $booking->getAlightWaypoint()?->getStopOrder();

                if ($bStart !== null && $bEnd !== null && $bStart <= $legIndex && $legIndex < $bEnd) {
                    $occupied += $booking->getSeatsCount() ?? 1;
                }
            }

            $breakdown[] = [
                'legIndex' => $legIndex,
                'fromCity' => $from->getCity()?->getName() ?? 'Start',
                'toCity' => $to->getCity()?->getName() ?? 'End',
                'fromOrder' => $legIndex,
                'toOrder' => $to->getStopOrder(),
                'totalSeats' => $totalSeats,
                'occupiedSeats' => $occupied,
                'availableSeats' => max(0, $totalSeats - $occupied),
            ];
        }

        return $breakdown;
    }
}
