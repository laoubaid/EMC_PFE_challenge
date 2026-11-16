<?php

namespace App\Repository;

use App\Entity\City;
use App\Entity\Trip;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Trip>
 */
class TripRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Trip::class);
    }

    /**
     * Search for trips that pass through origin city and then destination city in valid order.
     *
     * @return list<Trip>
     */
    public function findTripsBetweenCities(City $origin, City $destination, ?\DateTimeInterface $date = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->innerJoin('t.waypoints', 'w_origin')
            ->innerJoin('t.waypoints', 'w_dest')
            ->andWhere('w_origin.city = :origin')
            ->andWhere('w_dest.city = :destination')
            ->andWhere('w_origin.stopOrder < w_dest.stopOrder')
            ->andWhere('t.status = :status')
            ->setParameter('origin', $origin)
            ->setParameter('destination', $destination)
            ->setParameter('status', Trip::STATUS_SCHEDULED)
            ->orderBy('t.departureTime', 'ASC');

        if ($date !== null) {
            $startOfDay = \DateTimeImmutable::createFromInterface($date)->setTime(0, 0, 0);
            $endOfDay = $startOfDay->setTime(23, 59, 59);

            $qb->andWhere('w_origin.arrivalTime BETWEEN :startOfDay AND :endOfDay')
                ->setParameter('startOfDay', $startOfDay)
                ->setParameter('endOfDay', $endOfDay);
        } else {
            $qb->andWhere('t.departureTime >= :now')
                ->setParameter('now', new \DateTimeImmutable());
        }

        return $qb->getQuery()->getResult();
    }
}
