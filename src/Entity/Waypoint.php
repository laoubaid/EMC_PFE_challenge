<?php

namespace App\Entity;

use App\Repository\WaypointRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WaypointRepository::class)]
#[ORM\Table(name: 'waypoint')]
#[ORM\UniqueConstraint(name: 'uniq_trip_stop_order', columns: ['trip_id', 'stop_order'])]
class Waypoint
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'waypoints')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Trip $trip = null;

    #[ORM\ManyToOne(inversedBy: 'waypoints')]
    #[ORM\JoinColumn(nullable: false)]
    private ?City $city = null;

    #[ORM\Column(type: Types::INTEGER)]
    private ?int $stopOrder = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $arrivalTime = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, options: ['default' => '0.00'])]
    private string $distanceFromOriginKm = '0.00';

    /**
     * @var Collection<int, Booking>
     */
    #[ORM\OneToMany(targetEntity: Booking::class, mappedBy: 'boardWaypoint')]
    private Collection $boardBookings;

    /**
     * @var Collection<int, Booking>
     */
    #[ORM\OneToMany(targetEntity: Booking::class, mappedBy: 'alightWaypoint')]
    private Collection $alightBookings;

    public function __construct()
    {
        $this->boardBookings = new ArrayCollection();
        $this->alightBookings = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTrip(): ?Trip
    {
        return $this->trip;
    }

    public function setTrip(?Trip $trip): static
    {
        $this->trip = $trip;

        return $this;
    }

    public function getCity(): ?City
    {
        return $this->city;
    }

    public function setCity(?City $city): static
    {
        $this->city = $city;

        return $this;
    }

    public function getStopOrder(): ?int
    {
        return $this->stopOrder;
    }

    public function setStopOrder(int $stopOrder): static
    {
        $this->stopOrder = $stopOrder;

        return $this;
    }

    public function getArrivalTime(): ?\DateTimeImmutable
    {
        return $this->arrivalTime;
    }

    public function setArrivalTime(\DateTimeImmutable $arrivalTime): static
    {
        $this->arrivalTime = $arrivalTime;

        return $this;
    }

    public function getDistanceFromOriginKm(): string
    {
        return $this->distanceFromOriginKm;
    }

    public function setDistanceFromOriginKm(string|float $distanceFromOriginKm): static
    {
        $this->distanceFromOriginKm = number_format((float) $distanceFromOriginKm, 2, '.', '');

        return $this;
    }

    /**
     * @return Collection<int, Booking>
     */
    public function getBoardBookings(): Collection
    {
        return $this->boardBookings;
    }

    /**
     * @return Collection<int, Booking>
     */
    public function getAlightBookings(): Collection
    {
        return $this->alightBookings;
    }

    public function __toString(): string
    {
        $cityName = $this->city?->getName() ?? 'Unknown City';
        $time = $this->arrivalTime ? $this->arrivalTime->format('H:i') : '';

        return sprintf('Stop %d: %s (%s, %skm)', $this->stopOrder, $cityName, $time, $this->distanceFromOriginKm);
    }
}
