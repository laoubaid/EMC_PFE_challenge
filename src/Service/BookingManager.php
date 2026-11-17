<?php

namespace App\Service;

use App\Entity\Booking;
use App\Entity\Notification;
use App\Entity\Payment;
use App\Entity\Trip;
use App\Entity\User;
use App\Entity\Waypoint;
use App\Routing\TripRouteUpdater;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

class BookingManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SeatAvailabilityService $seatAvailabilityService,
        private readonly PricingCalculator $pricingCalculator
    ) {
    }

    /**
     * Creates a booking with simulated payment and pessimistic locking to avoid overbooking under concurrency.
     */
    public function createBooking(
        Trip $trip,
        User $passenger,
        Waypoint $board,
        Waypoint $alight,
        int $seatsCount = 1
    ): Booking {
        if ($seatsCount <= 0) {
            throw new \InvalidArgumentException('Number of seats must be greater than zero.');
        }

        if ($board->getTrip()->getId() !== $trip->getId() || $alight->getTrip()->getId() !== $trip->getId()) {
            throw new \InvalidArgumentException('Waypoints do not belong to the selected trip.');
        }

        if ($board->getStopOrder() >= $alight->getStopOrder()) {
            throw new \InvalidArgumentException('Boarding waypoint must occur before alighting waypoint.');
        }

        if ($passenger->getId() === $trip->getDriver()->getId()) {
            throw new \InvalidArgumentException('Driver cannot book seats on their own trip.');
        }

        // Run within a transaction with Pessimistic Write Lock on the Trip to prevent race conditions
        return $this->entityManager->wrapInTransaction(function () use ($trip, $passenger, $board, $alight, $seatsCount): Booking {
            // Lock trip row FOR UPDATE
            /** @var Trip $lockedTrip */
            $lockedTrip = $this->entityManager->find(
                Trip::class,
                $trip->getId(),
                LockMode::PESSIMISTIC_WRITE
            );

            if ($lockedTrip->getStatus() !== Trip::STATUS_SCHEDULED) {
                throw new \DomainException('Trip is not open for bookings.');
            }

            // Check availability with locked trip
            $available = $this->seatAvailabilityService->getAvailableSeatsForSegment($lockedTrip, $board, $alight);
            if ($available < $seatsCount) {
                throw new \DomainException(sprintf(
                    'Overbooking prevented: only %d seat(s) available on this segment, requested %d.',
                    $available,
                    $seatsCount
                ));
            }

            // Calculate distance and price using segment helper
            $segment = TripRouteUpdater::segment($board, $alight);
            $distance = $segment->distanceKm;
            $unitPrice = $this->pricingCalculator->calculateSegmentPrice($distance, $lockedTrip->getDriver()->getRatingAvg());
            $totalPrice = $unitPrice * $seatsCount;

            $booking = new Booking();
            $booking->setTrip($lockedTrip);
            $booking->setPassenger($passenger);
            $booking->setBoardWaypoint($board);
            $booking->setAlightWaypoint($alight);
            $booking->setSeatsCount($seatsCount);
            $booking->setDistanceKm($distance);
            $booking->setPrice($totalPrice);
            $booking->setStatus(Booking::STATUS_CONFIRMED);

            // Create simulated payment
            $payment = new Payment();
            $payment->setBooking($booking);
            $payment->setAmount($totalPrice);
            $payment->setStatus(Payment::STATUS_COMPLETED);
            $payment->setPaidAt(new \DateTimeImmutable());

            $booking->setPayment($payment);

            // Create driver notification
            $notification = new Notification();
            $notification->setUser($lockedTrip->getDriver());
            $notification->setTrip($lockedTrip);
            $notification->setType(Notification::TYPE_BOOKING_CONFIRMED);
            $notification->setMessage(sprintf(
                'New booking: %s booked %d seat(s) from %s to %s for %s DH.',
                $passenger->getFullName(),
                $seatsCount,
                $board->getCity()->getName(),
                $alight->getCity()->getName(),
                number_format($totalPrice, 2)
            ));

            $this->entityManager->persist($booking);
            $this->entityManager->persist($payment);
            $this->entityManager->persist($notification);

            return $booking;
        });
    }
}
