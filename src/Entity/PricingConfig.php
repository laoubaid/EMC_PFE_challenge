<?php

namespace App\Entity;

use App\Repository\PricingConfigRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PricingConfigRepository::class)]
class PricingConfig
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'pricingConfigs')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $updatedBy = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $pricePerKm = '1.5';

    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 4, options: ['default' => '0.0500'])]
    private string $baseMargin = '0.0500';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $effectiveFrom;

    public function __construct()
    {
        $this->effectiveFrom = new \DateTimeImmutable();
        $this->pricePerKm = '1.5';
        $this->baseMargin = '0.0500';
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    public function setUpdatedBy(?User $updatedBy): static
    {
        $this->updatedBy = $updatedBy;

        return $this;
    }

    public function getPricePerKm(): string
    {
        return $this->pricePerKm;
    }

    public function setPricePerKm(string|float $pricePerKm): static
    {
        $this->pricePerKm = number_format((float) $pricePerKm, 2, '.', '');

        return $this;
    }

    public function getBaseMargin(): string
    {
        return $this->baseMargin;
    }

    public function setBaseMargin(string|float $baseMargin): static
    {
        $this->baseMargin = number_format((float) $baseMargin, 4, '.', '');

        return $this;
    }

    public function getEffectiveFrom(): \DateTimeImmutable
    {
        return $this->effectiveFrom;
    }

    public function setEffectiveFrom(\DateTimeImmutable $effectiveFrom): static
    {
        $this->effectiveFrom = $effectiveFrom;

        return $this;
    }

    public function __toString(): string
    {
        return sprintf('PricingConfig (%s DH/km, %s%% margin from %s)', $this->pricePerKm, (float) $this->baseMargin * 100, $this->effectiveFrom->format('Y-m-d'));
    }
}
