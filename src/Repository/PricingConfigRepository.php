<?php

namespace App\Repository;

use App\Entity\PricingConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PricingConfig>
 */
class PricingConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PricingConfig::class);
    }

    /**
     * Get the latest effective pricing configuration.
     */
    public function getActiveConfig(): ?PricingConfig
    {
        return $this->createQueryBuilder('p')
            ->where('p.effectiveFrom <= :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('p.effectiveFrom', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
