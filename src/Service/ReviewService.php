<?php

namespace App\Service;

use App\Entity\Booking;
use App\Entity\Notification;
use App\Entity\Review;
use App\Entity\Trip;
use App\Entity\User;
use App\Repository\ReviewRepository;
use Doctrine\ORM\EntityManagerInterface;

class ReviewService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ?ReviewRepository $reviewRepository = null
    ) {
    }

    /**
     * Checks if author is eligible to review target for a given trip.
     * Criteria from DECISIONS.md:
     * - Trip must be completed.
     * - Mutual review: driver can review passenger (with confirmed/completed booking), and passenger can review driver.
     * - Only one review per trip for the same author and target.
     */
    public function canUserReview(Trip $trip, User $author, User $target): bool
    {
        if ($author->getId() === $target->getId()) {
            return false;
        }

        if ($trip->getStatus() !== Trip::STATUS_COMPLETED) {
            return false;
        }

        // Check if review already exists
        if ($this->reviewRepository) {
            $existing = $this->reviewRepository->findExistingReview($trip, $author, $target);
            if ($existing !== null) {
                return false;
            }
        }

        $isDriverAuthor = $trip->getDriver()->getId() === $author->getId();
        $isDriverTarget = $trip->getDriver()->getId() === $target->getId();

        if ($isDriverAuthor) {
            // Driver rating a passenger: check passenger was on the trip
            foreach ($trip->getBookings() as $booking) {
                if ($booking->getPassenger()->getId() === $target->getId()
                    && in_array($booking->getStatus(), [Booking::STATUS_CONFIRMED, Booking::STATUS_COMPLETED], true)) {
                    return true;
                }
            }
            return false;
        }

        if ($isDriverTarget) {
            // Passenger rating the driver: check author was a passenger on the trip
            foreach ($trip->getBookings() as $booking) {
                if ($booking->getPassenger()->getId() === $author->getId()
                    && in_array($booking->getStatus(), [Booking::STATUS_CONFIRMED, Booking::STATUS_COMPLETED], true)) {
                    return true;
                }
            }
            return false;
        }

        return false;
    }

    /**
     * Adds a review and updates the target's running average and rating count.
     */
    public function addReview(Trip $trip, User $author, User $target, int $rating, ?string $comment = null): Review
    {
        if (!$this->canUserReview($trip, $author, $target)) {
            throw new \DomainException('Not eligible to submit a review for this trip.');
        }

        $clampedRating = max(1, min(5, $rating));

        $review = new Review();
        $review->setTrip($trip);
        $review->setAuthor($author);
        $review->setTarget($target);
        $review->setRating($clampedRating);
        $review->setComment($comment);

        // Update target rating statistics
        $oldCount = $target->getRatingCount();
        $oldAvg = $target->getRatingAvg();

        $newCount = $oldCount + 1;
        $newAvg = round((($oldAvg * $oldCount) + $clampedRating) / $newCount, 2);

        $target->setRatingCount($newCount);
        $target->setRatingAvg($newAvg);

        // Notify target user
        $notification = new Notification();
        $notification->setUser($target);
        $notification->setTrip($trip);
        $notification->setType(Notification::TYPE_REVIEW_RECEIVED);
        $notification->setMessage(sprintf(
            '%s left you a %d/5 rating for trip #%d: "%s"',
            $author->getFullName(),
            $clampedRating,
            $trip->getId() ?? 0,
            $comment ? substr($comment, 0, 80) : 'Great trip!'
        ));

        $this->entityManager->persist($review);
        $this->entityManager->persist($notification);
        $this->entityManager->flush();

        return $review;
    }
}
