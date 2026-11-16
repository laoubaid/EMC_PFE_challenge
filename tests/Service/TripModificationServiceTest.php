<?php

namespace App\Tests\Service;

use App\Entity\Booking;
use App\Entity\Payment;
use App\Entity\Trip;
use App\Entity\User;
use App\Service\TripModificationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class TripModificationServiceTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private TripModificationService $service;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->service = new TripModificationService($this->entityManager);
    }

    public function testCannotModifyTripLessThan24HoursBeforeDeparture(): void
    {
        $trip = new Trip();
        $trip->setStatus(Trip::STATUS_SCHEDULED);
        $departure = new \DateTimeImmutable('2026-11-20 10:00:00');
        $trip->setDepartureTime($departure);

        // Reference time is 10 hours before departure (< 24h)
        $now = new \DateTimeImmutable('2026-11-20 00:00:00');

        $this->assertFalse($this->service->canModify($trip, $now));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Trips cannot be modified within 24 hours of departure');

        $this->service->modifyTrip($trip, ['departureTime' => $departure->modify('+1 hour')], $now);
    }

    public function testCanModifyTripMoreThan24HoursBeforeDeparture(): void
    {
        $driver = new User();
        $passenger = new User();

        $trip = new Trip();
        $trip->setDriver($driver);
        $trip->setStatus(Trip::STATUS_SCHEDULED);
        $departure = new \DateTimeImmutable('2026-11-25 10:00:00');
        $trip->setDepartureTime($departure);

        $booking = new Booking();
        $booking->setTrip($trip);
        $booking->setPassenger($passenger);
        $booking->setStatus(Booking::STATUS_CONFIRMED);
        $trip->getBookings()->add($booking);

        // Reference time: 48 hours before departure (> 24h)
        $now = new \DateTimeImmutable('2026-11-23 10:00:00');

        $this->assertTrue($this->service->canModify($trip, $now));

        $this->entityManager->expects($this->atLeastOnce())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $newDeparture = $departure->modify('+2 hours');
        $this->service->modifyTrip($trip, ['departureTime' => $newDeparture], $now);

        $this->assertSame($newDeparture, $trip->getDepartureTime());
        $this->assertSame(Booking::STATUS_PENDING_CONFIRMATION, $booking->getStatus());
    }

    public function testDriverCancellationWithin24HoursAppliesRatingPenaltyAndRefunds(): void
    {
        $driver = new User();
        $driver->setRatingAvg(4.5);
        $driver->setRatingCount(12);

        $passenger = new User();

        $trip = new Trip();
        $trip->setDriver($driver);
        $trip->setStatus(Trip::STATUS_SCHEDULED);
        $departure = new \DateTimeImmutable('2026-11-20 14:00:00');
        $trip->setDepartureTime($departure);

        $booking = new Booking();
        $booking->setTrip($trip);
        $booking->setPassenger($passenger);
        $booking->setPrice('250.00');
        $booking->setStatus(Booking::STATUS_CONFIRMED);

        $payment = new Payment();
        $payment->setBooking($booking);
        $payment->setAmount('250.00');
        $payment->setStatus(Payment::STATUS_COMPLETED);
        $booking->setPayment($payment);

        $trip->getBookings()->add($booking);

        // Cancellation 6 hours before departure (within 24h)
        $now = new \DateTimeImmutable('2026-11-20 08:00:00');

        $this->entityManager->expects($this->atLeastOnce())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $this->service->cancelTripByDriver($trip, $now);

        // Driver rating decreased by 1.0 penalty
        $this->assertEquals(3.5, $driver->getRatingAvg());
        $this->assertSame(Trip::STATUS_CANCELLED, $trip->getStatus());
        $this->assertSame(Booking::STATUS_CANCELLED_DRIVER, $booking->getStatus());
        $this->assertSame(Payment::STATUS_REFUNDED, $payment->getStatus());
        $this->assertNotNull($payment->getRefundedAt());
    }
}
