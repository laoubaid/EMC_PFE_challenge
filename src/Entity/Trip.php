<?php

namespace App\Entity;

use App\Repository\TripRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TripRepository::class)]
class Trip
{
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'tripsAsDriver')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $driver = null;

    #[ORM\ManyToOne(inversedBy: 'trips')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Vehicle $vehicle = null;

    #[ORM\Column(type: Types::INTEGER)]
    private ?int $totalSeats = null;

    #[ORM\Column(length: 30, options: ['default' => self::STATUS_SCHEDULED])]
    private string $status = self::STATUS_SCHEDULED;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $departureTime = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $modifiedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    /**
     * GeoJSON LineString representing the trip road geometry.
     * Stored format: ['type' => 'LineString', 'coordinates' => [[lon, lat], ...]]
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $routeGeometry = null;

    /**
     * @var Collection<int, Waypoint>
     */
    #[ORM\OneToMany(targetEntity: Waypoint::class, mappedBy: 'trip', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['stopOrder' => 'ASC'])]
    private Collection $waypoints;

    /**
     * @var Collection<int, Booking>
     */
    #[ORM\OneToMany(targetEntity: Booking::class, mappedBy: 'trip')]
    private Collection $bookings;

    /**
     * @var Collection<int, Review>
     */
    #[ORM\OneToMany(targetEntity: Review::class, mappedBy: 'trip')]
    private Collection $reviews;

    /**
     * @var Collection<int, Notification>
     */
    #[ORM\OneToMany(targetEntity: Notification::class, mappedBy: 'trip')]
    private Collection $notifications;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->status = self::STATUS_SCHEDULED;
        $this->waypoints = new ArrayCollection();
        $this->bookings = new ArrayCollection();
        $this->reviews = new ArrayCollection();
        $this->notifications = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDriver(): ?User
    {
        return $this->driver;
    }

    public function setDriver(?User $driver): static
    {
        $this->driver = $driver;

        return $this;
    }

    public function getVehicle(): ?Vehicle
    {
        return $this->vehicle;
    }

    public function setVehicle(?Vehicle $vehicle): static
    {
        $this->vehicle = $vehicle;
        if ($vehicle !== null && $this->totalSeats === null) {
            // Default trip total seats to vehicle capacity minus driver seat (or vehicle seats - 1)
            $this->totalSeats = max(1, $vehicle->getSeats() - 1);
        }

        return $this;
    }

    public function getTotalSeats(): ?int
    {
        return $this->totalSeats;
    }

    public function setTotalSeats(int $totalSeats): static
    {
        $this->totalSeats = $totalSeats;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getDepartureTime(): ?\DateTimeImmutable
    {
        return $this->departureTime;
    }

    public function setDepartureTime(\DateTimeImmutable $departureTime): static
    {
        $this->departureTime = $departureTime;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getModifiedAt(): ?\DateTimeImmutable
    {
        return $this->modifiedAt;
    }

    public function setModifiedAt(?\DateTimeImmutable $modifiedAt): static
    {
        $this->modifiedAt = $modifiedAt;

        return $this;
    }

    public function getCancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function setCancelledAt(?\DateTimeImmutable $cancelledAt): static
    {
        $this->cancelledAt = $cancelledAt;

        return $this;
    }

    /**
     * @return Collection<int, Waypoint>
     */
    public function getWaypoints(): Collection
    {
        return $this->waypoints;
    }

    public function addWaypoint(Waypoint $waypoint): static
    {
        if (!$this->waypoints->contains($waypoint)) {
            $this->waypoints->add($waypoint);
            $waypoint->setTrip($this);
        }

        return $this;
    }

    public function removeWaypoint(Waypoint $waypoint): static
    {
        if ($this->waypoints->removeElement($waypoint)) {
            if ($waypoint->getTrip() === $this) {
                $waypoint->setTrip(null);
            }
        }

        return $this;
    }

    /**
     * @return list<Waypoint>
     */
    public function getOrderedWaypoints(): array
    {
        $list = $this->waypoints->toArray();
        usort($list, static fn(Waypoint $a, Waypoint $b) => $a->getStopOrder() <=> $b->getStopOrder());

        return array_values($list);
    }

    public function getOriginWaypoint(): ?Waypoint
    {
        $ordered = $this->getOrderedWaypoints();

        return $ordered[0] ?? null;
    }

    public function getDestinationWaypoint(): ?Waypoint
    {
        $ordered = $this->getOrderedWaypoints();

        return !empty($ordered) ? $ordered[count($ordered) - 1] : null;
    }

    public function getOriginCity(): ?City
    {
        return $this->getOriginWaypoint()?->getCity();
    }

    public function getDestinationCity(): ?City
    {
        return $this->getDestinationWaypoint()?->getCity();
    }

    public function getTotalDistanceKm(): float
    {
        $dest = $this->getDestinationWaypoint();

        return $dest ? (float) $dest->getDistanceFromOriginKm() : 0.0;
    }

    public function getTotalDurationMin(): int
    {
        $dest = $this->getDestinationWaypoint();

        return $dest?->getDurationFromOriginMin() ?? 0;
    }

    public function getTotalDisplayEta(): string
    {
        $dest = $this->getDestinationWaypoint();
        if ($dest === null) {
            return '0 km';
        }

        return $dest->getDisplayEtaFromOrigin();
    }

    public function getRouteGeometry(): ?array
    {
        return $this->routeGeometry;
    }

    public function setRouteGeometry(?array $routeGeometry): static
    {
        $this->routeGeometry = $routeGeometry;

        return $this;
    }

    /**
     * Based on DECISIONS.md:
     * Modification is allowed only if more than 24 hours remain before departure.
     */
    public function canBeModified(?\DateTimeImmutable $referenceTime = null): bool
    {
        if ($this->status !== self::STATUS_SCHEDULED || $this->departureTime === null) {
            return false;
        }

        $now = $referenceTime ?? new \DateTimeImmutable();
        $diffHours = ($this->departureTime->getTimestamp() - $now->getTimestamp()) / 3600;

        return $diffHours > 24;
    }

    /**
     * @return Collection<int, Booking>
     */
    public function getBookings(): Collection
    {
        return $this->bookings;
    }

    /**
     * @return Collection<int, Review>
     */
    public function getReviews(): Collection
    {
        return $this->reviews;
    }

    /**
     * @return Collection<int, Notification>
     */
    public function getNotifications(): Collection
    {
        return $this->notifications;
    }

    public function __toString(): string
    {
        $from = $this->getOriginCity()?->getName() ?? 'Start';
        $to = $this->getDestinationCity()?->getName() ?? 'End';
        $date = $this->departureTime ? $this->departureTime->format('d/m/Y H:i') : '';

        return sprintf('#%d: %s -> %s (%s)', $this->id ?? 0, $from, $to, $date);
    }
}
