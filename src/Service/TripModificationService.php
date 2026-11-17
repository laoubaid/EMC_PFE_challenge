<?php

namespace App\Service;

use App\Entity\Booking;
use App\Entity\Notification;
use App\Entity\Payment;
use App\Entity\Trip;
use App\Routing\TripRouteUpdater;
use Doctrine\ORM\EntityManagerInterface;

class TripModificationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ?TripRouteUpdater $tripRouteUpdater = null
    ) {
    }

    /**
     * Checks if trip can be modified (allowed if departure is strictly more than 24h away).
     */
    public function canModify(Trip $trip, ?\DateTimeImmutable $now = null): bool
    {
        return $trip->canBeModified($now);
    }

    /**
     * Handle trip modification by driver according to DECISIONS.md:
     * - Less than 24h before departure: modification forbidden, driver can only cancel.
     * - More than 24h before departure: modification allowed, bookings set to pending_confirmation, passengers notified.
     *
     * @param array<string, mixed> $updates
     */
    public function modifyTrip(Trip $trip, array $updates, ?\DateTimeImmutable $now = null): void
    {
        $current = $now ?? new \DateTimeImmutable();

        if (!$this->canModify($trip, $current)) {
            throw new \DomainException(
                'Trips cannot be modified within 24 hours of departure. The trip can only be cancelled.'
            );
        }

        if (isset($updates['departureTime']) && $updates['departureTime'] instanceof \DateTimeImmutable) {
            $trip->setDepartureTime($updates['departureTime']);
            $this->tripRouteUpdater?->triggerRefresh($trip);
        }

        if (isset($updates['refreshRoute']) && $updates['refreshRoute'] === true) {
            $this->tripRouteUpdater?->triggerRefresh($trip);
        }

        $trip->setModifiedAt($current);

        // Put existing confirmed bookings in pending_confirmation and notify passengers
        foreach ($trip->getBookings() as $booking) {
            if ($booking->getStatus() === Booking::STATUS_CONFIRMED) {
                $booking->setStatus(Booking::STATUS_PENDING_CONFIRMATION);

                $notification = new Notification();
                $notification->setUser($booking->getPassenger());
                $notification->setTrip($trip);
                $notification->setType(Notification::TYPE_TRIP_MODIFIED);
                $notification->setMessage(sprintf(
                    'The driver modified trip #%d (%s -> %s). Please confirm your reservation or request a full refund.',
                    $trip->getId() ?? 0,
                    $trip->getOriginCity()?->getName() ?? 'Start',
                    $trip->getDestinationCity()?->getName() ?? 'End'
                ));

                $this->entityManager->persist($notification);
            }
        }

        $this->entityManager->flush();
    }

    /**
     * Driver cancels trip according to DECISIONS.md:
     * - Driver cancellation within 24h: penalty of -1.0 on driver rating!
     * - All passengers receive 100% refund and cancellation notification.
     */
    public function cancelTripByDriver(Trip $trip, ?\DateTimeImmutable $now = null): void
    {
        $current = $now ?? new \DateTimeImmutable();
        $trip->setStatus(Trip::STATUS_CANCELLED);
        $trip->setCancelledAt($current);

        // Check if within 24 hours of departure
        $diffHours = ($trip->getDepartureTime()->getTimestamp() - $current->getTimestamp()) / 3600;
        if ($diffHours <= 24) {
            // Apply rating penalty: driver gets -1 rating penalty (bounded at minimum 1.0)
            $driver = $trip->getDriver();
            if ($driver !== null) {
                $newRating = max(1.0, $driver->getRatingAvg() - 1.0);
                $driver->setRatingAvg($newRating);
            }
        }

        // Process all active bookings: refund and notify
        foreach ($trip->getBookings() as $booking) {
            if ($booking->isActive()) {
                $booking->setStatus(Booking::STATUS_CANCELLED_DRIVER);

                // Refund payment
                $payment = $booking->getPayment();
                if ($payment !== null && $payment->getStatus() === Payment::STATUS_COMPLETED) {
                    $payment->refund();
                }

                $notification = new Notification();
                $notification->setUser($booking->getPassenger());
                $notification->setTrip($trip);
                $notification->setType(Notification::TYPE_TRIP_CANCELLED);
                $notification->setMessage(sprintf(
                    'Trip #%d was cancelled by the driver. A full refund of %s DH has been credited to your simulated balance.',
                    $trip->getId() ?? 0,
                    $booking->getPrice()
                ));

                $this->entityManager->persist($notification);
            }
        }

        $this->entityManager->flush();
    }

    /**
     * Passenger confirms booking after driver modified the trip.
     */
    public function passengerConfirmModification(Booking $booking): void
    {
        if ($booking->getStatus() !== Booking::STATUS_PENDING_CONFIRMATION) {
            throw new \DomainException('Booking is not pending modification confirmation.');
        }

        $booking->setStatus(Booking::STATUS_CONFIRMED);
        $this->entityManager->flush();
    }

    /**
     * Passenger refuses modification and requests full refund.
     */
    public function passengerRefuseModification(Booking $booking): void
    {
        if ($booking->getStatus() !== Booking::STATUS_PENDING_CONFIRMATION) {
            throw new \DomainException('Booking is not pending modification confirmation.');
        }

        $booking->setStatus(Booking::STATUS_CANCELLED_PASSENGER);

        $payment = $booking->getPayment();
        if ($payment !== null && $payment->getStatus() === Payment::STATUS_COMPLETED) {
            $payment->refund();
        }

        // Notify driver
        $notification = new Notification();
        $notification->setUser($booking->getTrip()->getDriver());
        $notification->setTrip($booking->getTrip());
        $notification->setType(Notification::TYPE_BOOKING_CANCELLED);
        $notification->setMessage(sprintf(
            'Passenger %s declined the trip modification and their %d seat(s) were released.',
            $booking->getPassenger()->getFullName(),
            $booking->getSeatsCount()
        ));

        $this->entityManager->persist($notification);
        $this->entityManager->flush();
    }

    /**
     * Passenger cancels booking independently.
     */
    public function passengerCancelBooking(Booking $booking): void
    {
        $booking->setStatus(Booking::STATUS_CANCELLED_PASSENGER);

        $payment = $booking->getPayment();
        if ($payment !== null && $payment->getStatus() === Payment::STATUS_COMPLETED) {
            $payment->refund();
        }

        // Notify driver that seats have been freed
        $notification = new Notification();
        $notification->setUser($booking->getTrip()->getDriver());
        $notification->setTrip($booking->getTrip());
        $notification->setType(Notification::TYPE_BOOKING_CANCELLED);
        $notification->setMessage(sprintf(
            'Passenger %s cancelled booking #%d. %d seat(s) are now available again.',
            $booking->getPassenger()->getFullName(),
            $booking->getId() ?? 0,
            $booking->getSeatsCount()
        ));

        $this->entityManager->persist($notification);
        $this->entityManager->flush();
    }
}
