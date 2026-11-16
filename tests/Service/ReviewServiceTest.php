<?php

namespace App\Tests\Service;

use App\Entity\Booking;
use App\Entity\Trip;
use App\Entity\User;
use App\Repository\ReviewRepository;
use App\Service\ReviewService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class ReviewServiceTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private ReviewRepository $reviewRepository;
    private ReviewService $service;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->reviewRepository = $this->createMock(ReviewRepository::class);
        $this->service = new ReviewService($this->entityManager, $this->reviewRepository);
    }

    private function setEntityId(object $entity, int $id): void
    {
        $ref = new \ReflectionClass($entity);
        $prop = $ref->getProperty('id');
        $prop->setValue($entity, $id);
    }

    public function testCannotReviewIncompleteTrip(): void
    {
        $driver = new User();
        $this->setEntityId($driver, 1);

        $passenger = new User();
        $this->setEntityId($passenger, 2);

        $trip = new Trip();
        $this->setEntityId($trip, 10);
        $trip->setDriver($driver);
        $trip->setStatus(Trip::STATUS_SCHEDULED); // Not completed!

        $booking = new Booking();
        $booking->setPassenger($passenger);
        $booking->setStatus(Booking::STATUS_CONFIRMED);
        $trip->getBookings()->add($booking);

        $this->assertFalse($this->service->canUserReview($trip, $passenger, $driver));
    }

    public function testCannotReviewIfUserWasNotPartOfTrip(): void
    {
        $driver = new User();
        $this->setEntityId($driver, 1);

        $passenger = new User();
        $this->setEntityId($passenger, 2);

        $stranger = new User();
        $this->setEntityId($stranger, 3);

        $trip = new Trip();
        $this->setEntityId($trip, 10);
        $trip->setDriver($driver);
        $trip->setStatus(Trip::STATUS_COMPLETED);

        $booking = new Booking();
        $booking->setPassenger($passenger);
        $booking->setStatus(Booking::STATUS_COMPLETED);
        $trip->getBookings()->add($booking);

        $this->assertFalse($this->service->canUserReview($trip, $stranger, $driver));
    }

    public function testReviewUpdatesTargetRatingAndCount(): void
    {
        $driver = new User();
        $this->setEntityId($driver, 1);
        $driver->setRatingAvg(4.0);
        $driver->setRatingCount(10); // Standard baseline from DECISIONS.md

        $passenger = new User();
        $this->setEntityId($passenger, 2);

        $trip = new Trip();
        $this->setEntityId($trip, 10);
        $trip->setDriver($driver);
        $trip->setStatus(Trip::STATUS_COMPLETED);

        $booking = new Booking();
        $booking->setPassenger($passenger);
        $booking->setStatus(Booking::STATUS_COMPLETED);
        $trip->getBookings()->add($booking);

        $this->reviewRepository->method('findExistingReview')->willReturn(null);

        $this->entityManager->expects($this->atLeastOnce())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        // Passenger gives a 5-star rating to driver
        // New count = 10 + 1 = 11
        // New avg = (4.0 * 10 + 5) / 11 = 45 / 11 = 4.09
        $review = $this->service->addReview($trip, $passenger, $driver, 5, 'Super journey!');

        $this->assertSame(11, $driver->getRatingCount());
        $this->assertEquals(4.09, $driver->getRatingAvg());
        $this->assertSame(5, $review->getRating());
    }
}
