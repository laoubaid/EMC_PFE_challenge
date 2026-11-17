<?php

namespace App\Command;

use App\Entity\Booking;
use App\Entity\City;
use App\Entity\Notification;
use App\Entity\Payment;
use App\Entity\PricingConfig;
use App\Entity\Review;
use App\Entity\Trip;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Entity\Waypoint;
use App\Service\PricingCalculator;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:seed',
    description: 'Seed the database with realistic Moroccan cities, drivers, vehicles, multi-stop trips, bookings, and route geometries'
)]
class SeedDataCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly PricingCalculator $pricingCalculator
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('purge', null, InputOption::VALUE_NONE, 'Purge existing data before seeding');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Seeding Regional Carpooling Data (Morocco)');

        // Handle Purge Option
        if ($input->getOption('purge')) {
            $io->note('Purging existing data...');
            $conn = $this->entityManager->getConnection();
            $isSqlite = $conn->getDatabasePlatform() instanceof SqlitePlatform;

            if ($isSqlite) {
                $conn->executeStatement('PRAGMA foreign_keys = OFF');
            } else {
                $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
            }

            $tables = [
                'notification',
                'review',
                'payment',
                'booking',
                'waypoint',
                'trip',
                'vehicle',
                'pricing_config',
                'user',
                'city',
            ];

            foreach ($tables as $tbl) {
                $quotedTable = $isSqlite ? "\"$tbl\"" : "`$tbl`";
                try {
                    $conn->executeStatement("DELETE FROM $quotedTable");
                } catch (\Throwable) {
                    // Fallback without quotes if needed
                    $conn->executeStatement("DELETE FROM $tbl");
                }
            }

            if ($isSqlite) {
                $conn->executeStatement('PRAGMA foreign_keys = ON');
            } else {
                $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
            }

            $io->note('Database cleared successfully.');
        }

        // 1. Pricing Configuration
        $pricingConfig = new PricingConfig();
        $pricingConfig->setPricePerKm('1.5');
        $pricingConfig->setBaseMargin('0.0500');
        $pricingConfig->setEffectiveFrom(new \DateTimeImmutable('-1 month'));
        $this->entityManager->persist($pricingConfig);
        $io->note('Created active PricingConfig: 1.50 DH/km, 5% base margin.');

        // 2. Moroccan Cities with Real GPS Coordinates
        $moroccanCitiesData = [
            ['name' => 'Casablanca', 'lat' => 33.5731, 'lng' => -7.5898],
            ['name' => 'Rabat', 'lat' => 34.0209, 'lng' => -6.8416],
            ['name' => 'Marrakech', 'lat' => 31.6295, 'lng' => -7.9811],
            ['name' => 'Agadir', 'lat' => 30.4278, 'lng' => -9.5981],
            ['name' => 'Essaouira', 'lat' => 31.5085, 'lng' => -9.7595],
            ['name' => 'Tangier', 'lat' => 35.7595, 'lng' => -5.8340],
            ['name' => 'Fes', 'lat' => 34.0181, 'lng' => -5.0078],
            ['name' => 'Meknes', 'lat' => 33.8938, 'lng' => -5.5727],
            ['name' => 'Kenitra', 'lat' => 34.2610, 'lng' => -6.5802],
            ['name' => 'Mohammedia', 'lat' => 33.6866, 'lng' => -7.3829],
            ['name' => 'Tetouan', 'lat' => 35.5889, 'lng' => -5.3626],
            ['name' => 'El Jadida', 'lat' => 33.2549, 'lng' => -8.5060],
        ];

        /** @var array<string, City> $cities */
        $cities = [];
        foreach ($moroccanCitiesData as $cData) {
            $city = new City();
            $city->setName($cData['name']);
            $city->setLatitude($cData['lat']);
            $city->setLongitude($cData['lng']);
            $this->entityManager->persist($city);
            $cities[$cData['name']] = $city;
        }
        $io->note(sprintf('Created %d Moroccan cities with GPS coordinates.', count($cities)));

        // 3. Demo Users (Admin, 3 Drivers, 5 Passengers)
        // Admin
        $admin = new User();
        $admin->setEmail('admin@emc.ma');
        $admin->setFirstName('Admin');
        $admin->setLastName('Platform');
        $admin->setPhone('+212600000001');
        $admin->setIsAdmin(true);
        $admin->setPassword($this->passwordHasher->hashPassword($admin, 'admin123'));
        $this->entityManager->persist($admin);

        // Driver 1: Karim Benali (4.2 rating, 15 reviews)
        $driverKarim = new User();
        $driverKarim->setEmail('karim.driver@emc.ma');
        $driverKarim->setFirstName('Karim');
        $driverKarim->setLastName('Benali');
        $driverKarim->setPhone('+212611223344');
        $driverKarim->setRatingAvg(4.2);
        $driverKarim->setRatingCount(15);
        $driverKarim->addRole(User::ROLE_DRIVER);
        $driverKarim->setPassword($this->passwordHasher->hashPassword($driverKarim, 'driver123'));
        $this->entityManager->persist($driverKarim);

        // Driver 2: Samira Idrissi (4.8 rating, 20 reviews)
        $driverSamira = new User();
        $driverSamira->setEmail('samira.driver@emc.ma');
        $driverSamira->setFirstName('Samira');
        $driverSamira->setLastName('Idrissi');
        $driverSamira->setPhone('+212655667788');
        $driverSamira->setRatingAvg(4.8);
        $driverSamira->setRatingCount(20);
        $driverSamira->addRole(User::ROLE_DRIVER);
        $driverSamira->setPassword($this->passwordHasher->hashPassword($driverSamira, 'driver123'));
        $this->entityManager->persist($driverSamira);

        // Driver 3: Mehdi Tazi (3.9 rating after cancellation penalty, 8 reviews)
        $driverMehdi = new User();
        $driverMehdi->setEmail('mehdi.driver@emc.ma');
        $driverMehdi->setFirstName('Mehdi');
        $driverMehdi->setLastName('Tazi');
        $driverMehdi->setPhone('+212644556677');
        $driverMehdi->setRatingAvg(3.9);
        $driverMehdi->setRatingCount(8);
        $driverMehdi->addRole(User::ROLE_DRIVER);
        $driverMehdi->setPassword($this->passwordHasher->hashPassword($driverMehdi, 'driver123'));
        $this->entityManager->persist($driverMehdi);

        // Passengers
        $passengerYoussef = new User();
        $passengerYoussef->setEmail('youssef.passenger@emc.ma');
        $passengerYoussef->setFirstName('Youssef');
        $passengerYoussef->setLastName('Alami');
        $passengerYoussef->setPhone('+212677889900');
        $passengerYoussef->setRatingAvg(4.0);
        $passengerYoussef->setRatingCount(10);
        $passengerYoussef->setPassword($this->passwordHasher->hashPassword($passengerYoussef, 'pass123'));
        $this->entityManager->persist($passengerYoussef);

        $passengerFatima = new User();
        $passengerFatima->setEmail('fatima.passenger@emc.ma');
        $passengerFatima->setFirstName('Fatima');
        $passengerFatima->setLastName('Zahra');
        $passengerFatima->setPhone('+212699001122');
        $passengerFatima->setRatingAvg(4.5);
        $passengerFatima->setRatingCount(12);
        $passengerFatima->setPassword($this->passwordHasher->hashPassword($passengerFatima, 'pass123'));
        $this->entityManager->persist($passengerFatima);

        $passengerOmar = new User();
        $passengerOmar->setEmail('omar.passenger@emc.ma');
        $passengerOmar->setFirstName('Omar');
        $passengerOmar->setLastName('Bennani');
        $passengerOmar->setPhone('+212633445566');
        $passengerOmar->setRatingAvg(4.2);
        $passengerOmar->setRatingCount(7);
        $passengerOmar->setPassword($this->passwordHasher->hashPassword($passengerOmar, 'pass123'));
        $this->entityManager->persist($passengerOmar);

        $passengerAmina = new User();
        $passengerAmina->setEmail('amina.passenger@emc.ma');
        $passengerAmina->setFirstName('Amina');
        $passengerAmina->setLastName('Mansouri');
        $passengerAmina->setPhone('+212622334455');
        $passengerAmina->setRatingAvg(4.8);
        $passengerAmina->setRatingCount(14);
        $passengerAmina->setPassword($this->passwordHasher->hashPassword($passengerAmina, 'pass123'));
        $this->entityManager->persist($passengerAmina);

        $passengerHamza = new User();
        $passengerHamza->setEmail('hamza.passenger@emc.ma');
        $passengerHamza->setFirstName('Hamza');
        $passengerHamza->setLastName('Chraibi');
        $passengerHamza->setPhone('+212688776655');
        $passengerHamza->setRatingAvg(4.1);
        $passengerHamza->setRatingCount(5);
        $passengerHamza->setPassword($this->passwordHasher->hashPassword($passengerHamza, 'pass123'));
        $this->entityManager->persist($passengerHamza);

        $io->note('Created 9 demo users (1 Admin, 3 Drivers, 5 Passengers).');

        // 4. Vehicles
        $vehicleLogan = new Vehicle();
        $vehicleLogan->setOwner($driverKarim);
        $vehicleLogan->setBrand('Dacia');
        $vehicleLogan->setModel('Logan');
        $vehicleLogan->setPlate('12345-A-1');
        $vehicleLogan->setSeats(5);
        $this->entityManager->persist($vehicleLogan);

        $vehicleClio = new Vehicle();
        $vehicleClio->setOwner($driverSamira);
        $vehicleClio->setBrand('Renault');
        $vehicleClio->setModel('Clio 5');
        $vehicleClio->setPlate('67890-B-26');
        $vehicleClio->setSeats(5);
        $this->entityManager->persist($vehicleClio);

        $vehicle208 = new Vehicle();
        $vehicle208->setOwner($driverMehdi);
        $vehicle208->setBrand('Peugeot');
        $vehicle208->setModel('208');
        $vehicle208->setPlate('45678-C-6');
        $vehicle208->setSeats(5);
        $this->entityManager->persist($vehicle208);

        $vehicleTucson = new Vehicle();
        $vehicleTucson->setOwner($driverMehdi);
        $vehicleTucson->setBrand('Hyundai');
        $vehicleTucson->setModel('Tucson');
        $vehicleTucson->setPlate('98765-D-6');
        $vehicleTucson->setSeats(7);
        $this->entityManager->persist($vehicleTucson);

        // 5. Trips

        // ---------------------------------------------------------------------
        // TRIP 1: Scheduled (> 24h) Casablanca -> Marrakech -> Agadir
        // Demonstrates: Multi-stop route, intermediate seat reuse, modifiable trip
        // ---------------------------------------------------------------------
        $trip1 = new Trip();
        $trip1->setDriver($driverKarim);
        $trip1->setVehicle($vehicleLogan);
        $trip1->setTotalSeats(4);
        $trip1->setStatus(Trip::STATUS_SCHEDULED);
        $depTime1 = new \DateTimeImmutable('+2 days 08:00');
        $trip1->setDepartureTime($depTime1);
        $trip1->setRouteGeometry([
            'type' => 'LineString',
            'coordinates' => [
                [-7.5898, 33.5731], // Casablanca
                [-7.6160, 33.3900], // Berrechid
                [-7.6200, 33.0010], // Settat
                [-7.9500, 32.2300], // Benguerir
                [-7.9811, 31.6295], // Marrakech
                [-8.7600, 31.5400], // Chichaoua
                [-9.1200, 30.7800], // Argana
                [-9.5981, 30.4278], // Agadir
            ],
        ]);

        $wp1_0 = new Waypoint();
        $wp1_0->setTrip($trip1);
        $wp1_0->setCity($cities['Casablanca']);
        $wp1_0->setStopOrder(0);
        $wp1_0->setArrivalTime($depTime1);
        $wp1_0->setDistanceFromOriginKm(0.0);
        $wp1_0->setDurationFromOriginMin(0);

        $wp1_1 = new Waypoint();
        $wp1_1->setTrip($trip1);
        $wp1_1->setCity($cities['Marrakech']);
        $wp1_1->setStopOrder(1);
        $wp1_1->setArrivalTime($depTime1->modify('+2 hours 45 minutes'));
        $wp1_1->setDistanceFromOriginKm(242.5);
        $wp1_1->setDurationFromOriginMin(165);

        $wp1_2 = new Waypoint();
        $wp1_2->setTrip($trip1);
        $wp1_2->setCity($cities['Agadir']);
        $wp1_2->setStopOrder(2);
        $wp1_2->setArrivalTime($depTime1->modify('+5 hours 45 minutes'));
        $wp1_2->setDistanceFromOriginKm(502.8);
        $wp1_2->setDurationFromOriginMin(345);

        $trip1->addWaypoint($wp1_0);
        $trip1->addWaypoint($wp1_1);
        $trip1->addWaypoint($wp1_2);
        $this->entityManager->persist($trip1);

        // Booking 1: Youssef travels Casablanca -> Marrakech (1 seat)
        $dist1 = 242.5;
        $price1 = $this->pricingCalculator->calculateSegmentPrice($dist1, $driverKarim->getRatingAvg(), $pricingConfig);
        $booking1 = (new Booking())
            ->setTrip($trip1)
            ->setPassenger($passengerYoussef)
            ->setBoardWaypoint($wp1_0)
            ->setAlightWaypoint($wp1_1)
            ->setSeatsCount(1)
            ->setDistanceKm($dist1)
            ->setPrice($price1)
            ->setStatus(Booking::STATUS_CONFIRMED);
        $payment1 = (new Payment())
            ->setBooking($booking1)
            ->setAmount($price1)
            ->setStatus(Payment::STATUS_COMPLETED)
            ->setPaidAt(new \DateTimeImmutable('-3 hours'));
        $booking1->setPayment($payment1);
        $this->entityManager->persist($booking1);
        $this->entityManager->persist($payment1);

        // Booking 2: Fatima travels Marrakech -> Agadir (1 seat)
        // Note: Reuses the seat vacated by Youssef at Marrakech!
        $dist2 = 260.3; // 502.8 - 242.5
        $price2 = $this->pricingCalculator->calculateSegmentPrice($dist2, $driverKarim->getRatingAvg(), $pricingConfig);
        $booking2 = (new Booking())
            ->setTrip($trip1)
            ->setPassenger($passengerFatima)
            ->setBoardWaypoint($wp1_1)
            ->setAlightWaypoint($wp1_2)
            ->setSeatsCount(1)
            ->setDistanceKm($dist2)
            ->setPrice($price2)
            ->setStatus(Booking::STATUS_CONFIRMED);
        $payment2 = (new Payment())
            ->setBooking($booking2)
            ->setAmount($price2)
            ->setStatus(Payment::STATUS_COMPLETED)
            ->setPaidAt(new \DateTimeImmutable('-2 hours'));
        $booking2->setPayment($payment2);
        $this->entityManager->persist($booking2);
        $this->entityManager->persist($payment2);

        // Booking 3: Amina travels Casablanca -> Agadir entire trip (2 seats)
        $dist3 = 502.8;
        $unitPrice3 = $this->pricingCalculator->calculateSegmentPrice($dist3, $driverKarim->getRatingAvg(), $pricingConfig);
        $totalPrice3 = $unitPrice3 * 2;
        $booking3 = (new Booking())
            ->setTrip($trip1)
            ->setPassenger($passengerAmina)
            ->setBoardWaypoint($wp1_0)
            ->setAlightWaypoint($wp1_2)
            ->setSeatsCount(2)
            ->setDistanceKm($dist3)
            ->setPrice($totalPrice3)
            ->setStatus(Booking::STATUS_CONFIRMED);
        $payment3 = (new Payment())
            ->setBooking($booking3)
            ->setAmount($totalPrice3)
            ->setStatus(Payment::STATUS_COMPLETED)
            ->setPaidAt(new \DateTimeImmutable('-1 hour'));
        $booking3->setPayment($payment3);
        $this->entityManager->persist($booking3);
        $this->entityManager->persist($payment3);

        // Notification for Karim
        $notif1 = (new Notification())
            ->setUser($driverKarim)
            ->setTrip($trip1)
            ->setType(Notification::TYPE_BOOKING_CONFIRMED)
            ->setMessage('Amina Mansouri booked 2 seats from Casablanca to Agadir (502.8 km). 1 seat remains available.');
        $this->entityManager->persist($notif1);

        // ---------------------------------------------------------------------
        // TRIP 2: Scheduled (< 24h departure) Tangier -> Kenitra -> Rabat -> Casablanca
        // Demonstrates: Locked trip modification (< 24h threshold), 4-stop corridor
        // ---------------------------------------------------------------------
        $trip2 = new Trip();
        $trip2->setDriver($driverSamira);
        $trip2->setVehicle($vehicleClio);
        $trip2->setTotalSeats(4);
        $trip2->setStatus(Trip::STATUS_SCHEDULED);
        $depTime2 = new \DateTimeImmutable('+18 hours 07:30');
        $trip2->setDepartureTime($depTime2);
        $trip2->setRouteGeometry([
            'type' => 'LineString',
            'coordinates' => [
                [-5.8340, 35.7595], // Tangier
                [-6.0300, 35.4600], // Asilah
                [-6.1500, 35.1900], // Larache
                [-6.5802, 34.2610], // Kenitra
                [-6.8416, 34.0209], // Rabat
                [-7.3829, 33.6866], // Mohammedia
                [-7.5898, 33.5731], // Casablanca
            ],
        ]);

        $wp2_0 = (new Waypoint())
            ->setTrip($trip2)
            ->setCity($cities['Tangier'])
            ->setStopOrder(0)
            ->setArrivalTime($depTime2)
            ->setDistanceFromOriginKm(0.0)
            ->setDurationFromOriginMin(0);

        $wp2_1 = (new Waypoint())
            ->setTrip($trip2)
            ->setCity($cities['Kenitra'])
            ->setStopOrder(1)
            ->setArrivalTime($depTime2->modify('+2 hours 10 minutes'))
            ->setDistanceFromOriginKm(215.0)
            ->setDurationFromOriginMin(130);

        $wp2_2 = (new Waypoint())
            ->setTrip($trip2)
            ->setCity($cities['Rabat'])
            ->setStopOrder(2)
            ->setArrivalTime($depTime2->modify('+2 hours 40 minutes'))
            ->setDistanceFromOriginKm(255.4)
            ->setDurationFromOriginMin(160);

        $wp2_3 = (new Waypoint())
            ->setTrip($trip2)
            ->setCity($cities['Casablanca'])
            ->setStopOrder(3)
            ->setArrivalTime($depTime2->modify('+3 hours 45 minutes'))
            ->setDistanceFromOriginKm(342.1)
            ->setDurationFromOriginMin(225);

        $trip2->addWaypoint($wp2_0);
        $trip2->addWaypoint($wp2_1);
        $trip2->addWaypoint($wp2_2);
        $trip2->addWaypoint($wp2_3);
        $this->entityManager->persist($trip2);

        // Booking on Trip 2: Omar travels Tangier -> Rabat (1 seat)
        $distTrip2 = 255.4;
        $priceTrip2 = $this->pricingCalculator->calculateSegmentPrice($distTrip2, $driverSamira->getRatingAvg(), $pricingConfig);
        $bookingTrip2 = (new Booking())
            ->setTrip($trip2)
            ->setPassenger($passengerOmar)
            ->setBoardWaypoint($wp2_0)
            ->setAlightWaypoint($wp2_2)
            ->setSeatsCount(1)
            ->setDistanceKm($distTrip2)
            ->setPrice($priceTrip2)
            ->setStatus(Booking::STATUS_CONFIRMED);
        $paymentTrip2 = (new Payment())
            ->setBooking($bookingTrip2)
            ->setAmount($priceTrip2)
            ->setStatus(Payment::STATUS_COMPLETED)
            ->setPaidAt(new \DateTimeImmutable('-4 hours'));
        $bookingTrip2->setPayment($paymentTrip2);
        $this->entityManager->persist($bookingTrip2);
        $this->entityManager->persist($paymentTrip2);

        // ---------------------------------------------------------------------
        // TRIP 3: Completed Trip Marrakech -> Essaouira with Mutual Two-Way Reviews
        // Demonstrates: Completed status, mutual reviews, ratings reflection
        // ---------------------------------------------------------------------
        $trip3 = new Trip();
        $trip3->setDriver($driverKarim);
        $trip3->setVehicle($vehicleLogan);
        $trip3->setTotalSeats(4);
        $trip3->setStatus(Trip::STATUS_COMPLETED);
        $depTime3 = new \DateTimeImmutable('-3 days 09:00');
        $trip3->setDepartureTime($depTime3);
        $trip3->setRouteGeometry([
            'type' => 'LineString',
            'coordinates' => [
                [-7.9811, 31.6295], // Marrakech
                [-8.7600, 31.5400], // Chichaoua
                [-9.1500, 31.5200], // Sidi Mokhtar
                [-9.5500, 31.5100], // Ounagha
                [-9.7595, 31.5085], // Essaouira
            ],
        ]);

        $wp3_0 = (new Waypoint())
            ->setTrip($trip3)
            ->setCity($cities['Marrakech'])
            ->setStopOrder(0)
            ->setArrivalTime($depTime3)
            ->setDistanceFromOriginKm(0.0)
            ->setDurationFromOriginMin(0);

        $wp3_1 = (new Waypoint())
            ->setTrip($trip3)
            ->setCity($cities['Essaouira'])
            ->setStopOrder(1)
            ->setArrivalTime($depTime3->modify('+2 hours 40 minutes'))
            ->setDistanceFromOriginKm(178.6)
            ->setDurationFromOriginMin(160);

        $trip3->addWaypoint($wp3_0);
        $trip3->addWaypoint($wp3_1);
        $this->entityManager->persist($trip3);

        $distTrip3 = 178.6;
        $unitPriceTrip3 = $this->pricingCalculator->calculateSegmentPrice($distTrip3, $driverKarim->getRatingAvg(), $pricingConfig);
        $totalPriceTrip3 = $unitPriceTrip3 * 2;

        $bookingTrip3 = (new Booking())
            ->setTrip($trip3)
            ->setPassenger($passengerHamza)
            ->setBoardWaypoint($wp3_0)
            ->setAlightWaypoint($wp3_1)
            ->setSeatsCount(2)
            ->setDistanceKm($distTrip3)
            ->setPrice($totalPriceTrip3)
            ->setStatus(Booking::STATUS_COMPLETED);
        $paymentTrip3 = (new Payment())
            ->setBooking($bookingTrip3)
            ->setAmount($totalPriceTrip3)
            ->setStatus(Payment::STATUS_COMPLETED)
            ->setPaidAt($depTime3->modify('-1 day'));
        $bookingTrip3->setPayment($paymentTrip3);
        $this->entityManager->persist($bookingTrip3);
        $this->entityManager->persist($paymentTrip3);

        // Mutual reviews on completed trip 3:
        $review1 = (new Review())
            ->setTrip($trip3)
            ->setAuthor($passengerHamza)
            ->setTarget($driverKarim)
            ->setRating(5)
            ->setComment('Great and smooth journey! Very safe driving through the argan plains and punctual departure.');
        $this->entityManager->persist($review1);

        $review2 = (new Review())
            ->setTrip($trip3)
            ->setAuthor($driverKarim)
            ->setTarget($passengerHamza)
            ->setRating(5)
            ->setComment('Excellent passenger, very polite, arrived on time at the Marrakech pickup spot.');
        $this->entityManager->persist($review2);

        // ---------------------------------------------------------------------
        // TRIP 4: Completed Multi-Stop Corridor Fes -> Meknes -> Rabat -> Casablanca
        // Demonstrates: Multi-stop completed trip with multiple passenger reviews
        // ---------------------------------------------------------------------
        $trip4 = new Trip();
        $trip4->setDriver($driverMehdi);
        $trip4->setVehicle($vehicleTucson);
        $trip4->setTotalSeats(6);
        $trip4->setStatus(Trip::STATUS_COMPLETED);
        $depTime4 = new \DateTimeImmutable('-5 days 08:30');
        $trip4->setDepartureTime($depTime4);
        $trip4->setRouteGeometry([
            'type' => 'LineString',
            'coordinates' => [
                [-5.0078, 34.0181], // Fes
                [-5.5727, 33.8938], // Meknes
                [-6.0700, 33.8200], // Khemisset
                [-6.3100, 33.8900], // Tiflet
                [-6.8416, 34.0209], // Rabat
                [-7.3829, 33.6866], // Mohammedia
                [-7.5898, 33.5731], // Casablanca
            ],
        ]);

        $wp4_0 = (new Waypoint())
            ->setTrip($trip4)
            ->setCity($cities['Fes'])
            ->setStopOrder(0)
            ->setArrivalTime($depTime4)
            ->setDistanceFromOriginKm(0.0)
            ->setDurationFromOriginMin(0);

        $wp4_1 = (new Waypoint())
            ->setTrip($trip4)
            ->setCity($cities['Meknes'])
            ->setStopOrder(1)
            ->setArrivalTime($depTime4->modify('+50 minutes'))
            ->setDistanceFromOriginKm(62.3)
            ->setDurationFromOriginMin(50);

        $wp4_2 = (new Waypoint())
            ->setTrip($trip4)
            ->setCity($cities['Rabat'])
            ->setStopOrder(2)
            ->setArrivalTime($depTime4->modify('+2 hours 25 minutes'))
            ->setDistanceFromOriginKm(198.7)
            ->setDurationFromOriginMin(145);

        $wp4_3 = (new Waypoint())
            ->setTrip($trip4)
            ->setCity($cities['Casablanca'])
            ->setStopOrder(3)
            ->setArrivalTime($depTime4->modify('+3 hours 30 minutes'))
            ->setDistanceFromOriginKm(285.2)
            ->setDurationFromOriginMin(210);

        $trip4->addWaypoint($wp4_0);
        $trip4->addWaypoint($wp4_1);
        $trip4->addWaypoint($wp4_2);
        $trip4->addWaypoint($wp4_3);
        $this->entityManager->persist($trip4);

        // Booking 4A: Fatima travels Fes -> Casablanca (1 seat)
        $dist4A = 285.2;
        $price4A = $this->pricingCalculator->calculateSegmentPrice($dist4A, $driverMehdi->getRatingAvg(), $pricingConfig);
        $booking4A = (new Booking())
            ->setTrip($trip4)
            ->setPassenger($passengerFatima)
            ->setBoardWaypoint($wp4_0)
            ->setAlightWaypoint($wp4_3)
            ->setSeatsCount(1)
            ->setDistanceKm($dist4A)
            ->setPrice($price4A)
            ->setStatus(Booking::STATUS_COMPLETED);
        $payment4A = (new Payment())
            ->setBooking($booking4A)
            ->setAmount($price4A)
            ->setStatus(Payment::STATUS_COMPLETED)
            ->setPaidAt($depTime4->modify('-1 day'));
        $booking4A->setPayment($payment4A);
        $this->entityManager->persist($booking4A);
        $this->entityManager->persist($payment4A);

        // Booking 4B: Amina travels Meknes -> Rabat (2 seats)
        $dist4B = 136.4; // 198.7 - 62.3
        $unitPrice4B = $this->pricingCalculator->calculateSegmentPrice($dist4B, $driverMehdi->getRatingAvg(), $pricingConfig);
        $totalPrice4B = $unitPrice4B * 2;
        $booking4B = (new Booking())
            ->setTrip($trip4)
            ->setPassenger($passengerAmina)
            ->setBoardWaypoint($wp4_1)
            ->setAlightWaypoint($wp4_2)
            ->setSeatsCount(2)
            ->setDistanceKm($dist4B)
            ->setPrice($totalPrice4B)
            ->setStatus(Booking::STATUS_COMPLETED);
        $payment4B = (new Payment())
            ->setBooking($booking4B)
            ->setAmount($totalPrice4B)
            ->setStatus(Payment::STATUS_COMPLETED)
            ->setPaidAt($depTime4->modify('-1 day'));
        $booking4B->setPayment($payment4B);
        $this->entityManager->persist($booking4B);
        $this->entityManager->persist($payment4B);

        // Reviews for Trip 4
        $review3 = (new Review())
            ->setTrip($trip4)
            ->setAuthor($passengerFatima)
            ->setTarget($driverMehdi)
            ->setRating(4)
            ->setComment('Comfortable Tucson SUV, clean and spacious. Minor 10-minute delay at Meknes pickup but overall great trip.');
        $this->entityManager->persist($review3);

        $review4 = (new Review())
            ->setTrip($trip4)
            ->setAuthor($driverMehdi)
            ->setTarget($passengerFatima)
            ->setRating(5)
            ->setComment('Very respectful passenger. Would gladly travel again!');
        $this->entityManager->persist($review4);

        // ---------------------------------------------------------------------
        // TRIP 5: Cancelled by Driver (< 24h) Casablanca -> Mohammedia -> Rabat
        // Demonstrates: Cancellation penalty (-1.0 rating), passenger refund & notifications
        // ---------------------------------------------------------------------
        $trip5 = new Trip();
        $trip5->setDriver($driverMehdi);
        $trip5->setVehicle($vehicle208);
        $trip5->setTotalSeats(4);
        $trip5->setStatus(Trip::STATUS_CANCELLED);
        $depTime5 = new \DateTimeImmutable('-1 day 14:00');
        $trip5->setDepartureTime($depTime5);
        $trip5->setCancelledAt($depTime5->modify('-12 hours')); // Cancelled 12h before departure (< 24h)
        $trip5->setRouteGeometry([
            'type' => 'LineString',
            'coordinates' => [
                [-7.5898, 33.5731], // Casablanca
                [-7.3829, 33.6866], // Mohammedia
                [-7.1500, 33.8200], // Bouznika
                [-6.8416, 34.0209], // Rabat
            ],
        ]);

        $wp5_0 = (new Waypoint())
            ->setTrip($trip5)
            ->setCity($cities['Casablanca'])
            ->setStopOrder(0)
            ->setArrivalTime($depTime5)
            ->setDistanceFromOriginKm(0.0)
            ->setDurationFromOriginMin(0);

        $wp5_1 = (new Waypoint())
            ->setTrip($trip5)
            ->setCity($cities['Mohammedia'])
            ->setStopOrder(1)
            ->setArrivalTime($depTime5->modify('+30 minutes'))
            ->setDistanceFromOriginKm(28.4)
            ->setDurationFromOriginMin(30);

        $wp5_2 = (new Waypoint())
            ->setTrip($trip5)
            ->setCity($cities['Rabat'])
            ->setStopOrder(2)
            ->setArrivalTime($depTime5->modify('+1 hour 15 minutes'))
            ->setDistanceFromOriginKm(87.2)
            ->setDurationFromOriginMin(75);

        $trip5->addWaypoint($wp5_0);
        $trip5->addWaypoint($wp5_1);
        $trip5->addWaypoint($wp5_2);
        $this->entityManager->persist($trip5);

        // Booking on Trip 5: Youssef booked Casablanca -> Rabat, now cancelled & refunded
        $distTrip5 = 87.2;
        $priceTrip5 = $this->pricingCalculator->calculateSegmentPrice($distTrip5, 4.9, $pricingConfig);
        $bookingTrip5 = (new Booking())
            ->setTrip($trip5)
            ->setPassenger($passengerYoussef)
            ->setBoardWaypoint($wp5_0)
            ->setAlightWaypoint($wp5_2)
            ->setSeatsCount(1)
            ->setDistanceKm($distTrip5)
            ->setPrice($priceTrip5)
            ->setStatus(Booking::STATUS_CANCELLED_DRIVER);
        $paymentTrip5 = (new Payment())
            ->setBooking($bookingTrip5)
            ->setAmount($priceTrip5)
            ->setStatus(Payment::STATUS_REFUNDED)
            ->setPaidAt($depTime5->modify('-2 days'))
            ->setRefundedAt($depTime5->modify('-12 hours'));
        $bookingTrip5->setPayment($paymentTrip5);
        $this->entityManager->persist($bookingTrip5);
        $this->entityManager->persist($paymentTrip5);

        // Notifications for Trip 5
        $notifPassenger = (new Notification())
            ->setUser($passengerYoussef)
            ->setTrip($trip5)
            ->setType(Notification::TYPE_REFUND_ISSUED)
            ->setMessage(sprintf(
                'Your trip #%d from Casablanca to Rabat was cancelled by the driver. A full refund of %.2f DH has been processed.',
                $trip5->getId() ?? 5,
                $priceTrip5
            ));
        $this->entityManager->persist($notifPassenger);

        $notifDriverPenalty = (new Notification())
            ->setUser($driverMehdi)
            ->setTrip($trip5)
            ->setType(Notification::TYPE_TRIP_CANCELLED)
            ->setMessage('You cancelled trip #5 with less than 24 hours notice before departure. A penalty of -1.0 point was applied to your driver rating.');
        $this->entityManager->persist($notifDriverPenalty);

        // ---------------------------------------------------------------------
        // TRIP 6: Scheduled Future Trip (> 24h) Rabat -> Tangier
        // Demonstrates: Open trip with full seat availability, ready for booking
        // ---------------------------------------------------------------------
        $trip6 = new Trip();
        $trip6->setDriver($driverSamira);
        $trip6->setVehicle($vehicleClio);
        $trip6->setTotalSeats(4);
        $trip6->setStatus(Trip::STATUS_SCHEDULED);
        $depTime6 = new \DateTimeImmutable('+4 days 16:00');
        $trip6->setDepartureTime($depTime6);
        $trip6->setRouteGeometry([
            'type' => 'LineString',
            'coordinates' => [
                [-6.8416, 34.0209], // Rabat
                [-6.5802, 34.2610], // Kenitra
                [-6.1500, 35.1900], // Larache
                [-6.0300, 35.4600], // Asilah
                [-5.8340, 35.7595], // Tangier
            ],
        ]);

        $wp6_0 = (new Waypoint())
            ->setTrip($trip6)
            ->setCity($cities['Rabat'])
            ->setStopOrder(0)
            ->setArrivalTime($depTime6)
            ->setDistanceFromOriginKm(0.0)
            ->setDurationFromOriginMin(0);

        $wp6_1 = (new Waypoint())
            ->setTrip($trip6)
            ->setCity($cities['Tangier'])
            ->setStopOrder(1)
            ->setArrivalTime($depTime6->modify('+2 hours 40 minutes'))
            ->setDistanceFromOriginKm(255.4)
            ->setDurationFromOriginMin(160);

        $trip6->addWaypoint($wp6_0);
        $trip6->addWaypoint($wp6_1);
        $this->entityManager->persist($trip6);

        $this->entityManager->flush();

        $io->success([
            'Regional Carpooling demo data successfully seeded!',
            '==================================================',
            'Demo Accounts & Credentials:',
            '  * Admin:       admin@emc.ma              / admin123',
            '  * Driver 1:    karim.driver@emc.ma       / driver123  (Rating 4.2, Dacia Logan)',
            '  * Driver 2:    samira.driver@emc.ma      / driver123  (Rating 4.8, Renault Clio 5)',
            '  * Driver 3:    mehdi.driver@emc.ma       / driver123  (Rating 3.9, Peugeot 208, Hyundai Tucson)',
            '  * Passenger 1: youssef.passenger@emc.ma  / pass123',
            '  * Passenger 2: fatima.passenger@emc.ma   / pass123',
            '  * Passenger 3: omar.passenger@emc.ma     / pass123',
            '  * Passenger 4: amina.passenger@emc.ma    / pass123',
            '  * Passenger 5: hamza.passenger@emc.ma    / pass123',
            '--------------------------------------------------',
            'Sample Trip Scenarios Ready to Test:',
            '  - Trip 1: Casablanca -> Marrakech -> Agadir (Scheduled >24h, intermediate seat reuse)',
            '  - Trip 2: Tangier -> Kenitra -> Rabat -> Casablanca (Scheduled <24h, locked modification)',
            '  - Trip 3: Marrakech -> Essaouira (Completed, 2-way reviews)',
            '  - Trip 4: Fes -> Meknes -> Rabat -> Casablanca (Completed, multi-stop)',
            '  - Trip 5: Casablanca -> Mohammedia -> Rabat (Cancelled by driver <24h, penalty & refund)',
            '  - Trip 6: Rabat -> Tangier (Scheduled >24h, 4 open seats ready for booking)',
        ]);

        return Command::SUCCESS;
    }
}
