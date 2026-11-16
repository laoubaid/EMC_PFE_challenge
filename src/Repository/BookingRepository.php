<?php

namespace App\Repository;

use App\Entity\Booking;
use App\Entity\Trip;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Booking>
 */
class BookingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Booking::class);
    }

    /**
     * Find active bookings for a trip (confirmed or pending confirmation)
     *
     * @return list<Booking>
     */
    public function findActiveBookingsForTrip(Trip $trip): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.trip = :trip')
            ->andWhere('b.status IN (:activeStatuses)')
            ->setParameter('trip', $trip)
            ->setParameter('activeStatuses', [
                Booking::STATUS_CONFIRMED,
                Booking::STATUS_PENDING_CONFIRMATION,
            ])
            ->getQuery()
            ->getResult();
    }
}
