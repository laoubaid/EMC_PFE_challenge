<?php

namespace App\Entity;

use App\Repository\BookingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BookingRepository::class)]
class Booking
{
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_PENDING_CONFIRMATION = 'pending_confirmation';
    public const STATUS_CANCELLED_PASSENGER = 'cancelled_by_passenger';
    public const STATUS_CANCELLED_DRIVER = 'cancelled_by_driver';
    public const STATUS_COMPLETED = 'completed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'bookings')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Trip $trip = null;

    #[ORM\ManyToOne(inversedBy: 'bookings')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $passenger = null;

    #[ORM\ManyToOne(inversedBy: 'boardBookings')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Waypoint $boardWaypoint = null;

    #[ORM\ManyToOne(inversedBy: 'alightBookings')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Waypoint $alightWaypoint = null;

    #[ORM\Column(type: Types::INTEGER)]
    private ?int $seatsCount = 1;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $distanceKm = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $price = '0.00';

    #[ORM\Column(length: 40, options: ['default' => self::STATUS_CONFIRMED])]
    private string $status = self::STATUS_CONFIRMED;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\OneToOne(targetEntity: Payment::class, mappedBy: 'booking', cascade: ['persist', 'remove'])]
    private ?Payment $payment = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->status = self::STATUS_CONFIRMED;
        $this->seatsCount = 1;
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

    public function getPassenger(): ?User
    {
        return $this->passenger;
    }

    public function setPassenger(?User $passenger): static
    {
        $this->passenger = $passenger;

        return $this;
    }

    public function getBoardWaypoint(): ?Waypoint
    {
        return $this->boardWaypoint;
    }

    public function setBoardWaypoint(?Waypoint $boardWaypoint): static
    {
        $this->boardWaypoint = $boardWaypoint;

        return $this;
    }

    public function getAlightWaypoint(): ?Waypoint
    {
        return $this->alightWaypoint;
    }

    public function setAlightWaypoint(?Waypoint $alightWaypoint): static
    {
        $this->alightWaypoint = $alightWaypoint;

        return $this;
    }

    public function getSeatsCount(): ?int
    {
        return $this->seatsCount;
    }

    public function setSeatsCount(int $seatsCount): static
    {
        $this->seatsCount = $seatsCount;

        return $this;
    }

    public function getDistanceKm(): string
    {
        return $this->distanceKm;
    }

    public function setDistanceKm(string|float $distanceKm): static
    {
        $this->distanceKm = number_format((float) $distanceKm, 2, '.', '');

        return $this;
    }

    public function getPrice(): string
    {
        return $this->price;
    }

    public function setPrice(string|float $price): static
    {
        $this->price = number_format((float) $price, 2, '.', '');

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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getPayment(): ?Payment
    {
        return $this->payment;
    }

    public function setPayment(?Payment $payment): static
    {
        // set the owning side of the relation if necessary
        if ($payment !== null && $payment->getBooking() !== $this) {
            $payment->setBooking($this);
        }

        $this->payment = $payment;

        return $this;
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_CONFIRMED, self::STATUS_PENDING_CONFIRMATION], true);
    }

    public function __toString(): string
    {
        $from = $this->boardWaypoint?->getCity()?->getName() ?? '?';
        $to = $this->alightWaypoint?->getCity()?->getName() ?? '?';

        return sprintf('Booking #%d (%s -> %s, %d seats, %s DH)', $this->id ?? 0, $from, $to, $this->seatsCount, $this->price);
    }
}
