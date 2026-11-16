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
    description: 'Seed the database with realistic Moroccan cities, drivers, vehicles, multi-stop trips, and bookings'
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

        // 1. Pricing Configuration
        $pricingConfig = new PricingConfig();
        $pricingConfig->setPricePerKm('1.5');
        $pricingConfig->setBaseMargin('0.0500');
        $pricingConfig->setEffectiveFrom(new \DateTimeImmutable('-1 month'));
        $this->entityManager->persist($pricingConfig);
        $io->note('Created active PricingConfig: 1.50 DH/km, 5% base margin.');

        // 2. Moroccan Cities with real GPS coordinates
        $moroccanCitiesData = [
            ['name' => 'Casablanca', 'lat' => 33.5731, 'lng' => -7.5898],
            ['name' => 'Rabat', 'lat' => 34.0209, 'lng' => -6.8416],
            ['name' => 'Marrakech', 'lat' => 31.6295, 'lng' => -7.9811],
            ['name' => 'Agadir', 'lat' => 30.4278, 'lng' => -9.5981],
            ['name' => 'Essaouira', 'lat' => 31.5085, 'lng' => -9.7595],
            ['name' => 'Tangier', 'lat' => 35.7595, 'lng' => -5.8340],
            ['name' => 'Fes', 'lat' => 34.0181, 'lng' => -5.0078],
            ['name' => 'Meknes', 'lat' => 33.8938, 'lng' => -5.5727],
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

        // 3. Demo Users (Admin, Drivers, Passengers)
        // Admin
        $admin = new User();
        $admin->setEmail('admin@emc.ma');
        $admin->setFirstName('Admin');
        $admin->setLastName('Platform');
        $admin->setPhone('+212600000001');
        $admin->setIsAdmin(true);
        $admin->setPassword($this->passwordHasher->hashPassword($admin, 'admin123'));
        $this->entityManager->persist($admin);

        // Driver 1: Karim Benali
        $driverKarim = new User();
        $driverKarim->setEmail('karim.driver@emc.ma');
        $driverKarim->setFirstName('Karim');
        $driverKarim->setLastName('Benali');
        $driverKarim->setPhone('+212611223344');
        $driverKarim->setRatingAvg(4.2);
        $driverKarim->setRatingCount(15);
        $driverKarim->setPassword($this->passwordHasher->hashPassword($driverKarim, 'driver123'));
        $this->entityManager->persist($driverKarim);

        // Driver 2: Samira Idrissi
        $driverSamira = new User();
        $driverSamira->setEmail('samira.driver@emc.ma');
        $driverSamira->setFirstName('Samira');
        $driverSamira->setLastName('Idrissi');
        $driverSamira->setPhone('+212655667788');
        $driverSamira->setRatingAvg(4.8);
        $driverSamira->setRatingCount(20);
        $driverSamira->setPassword($this->passwordHasher->hashPassword($driverSamira, 'driver123'));
        $this->entityManager->persist($driverSamira);

        // Passenger 1: Youssef Alami
        $passengerYoussef = new User();
        $passengerYoussef->setEmail('youssef.passenger@emc.ma');
        $passengerYoussef->setFirstName('Youssef');
        $passengerYoussef->setLastName('Alami');
        $passengerYoussef->setPhone('+212677889900');
        $passengerYoussef->setRatingAvg(4.0);
        $passengerYoussef->setRatingCount(10);
        $passengerYoussef->setPassword($this->passwordHasher->hashPassword($passengerYoussef, 'pass123'));
        $this->entityManager->persist($passengerYoussef);

        // Passenger 2: Fatima Zahra
        $passengerFatima = new User();
        $passengerFatima->setEmail('fatima.passenger@emc.ma');
        $passengerFatima->setFirstName('Fatima');
        $passengerFatima->setLastName('Zahra');
        $passengerFatima->setPhone('+212699001122');
        $passengerFatima->setRatingAvg(4.0);
        $passengerFatima->setRatingCount(10);
        $passengerFatima->setPassword($this->passwordHasher->hashPassword($passengerFatima, 'pass123'));
        $this->entityManager->persist($passengerFatima);

        // Passenger 3: Omar Tazi
        $passengerOmar = new User();
        $passengerOmar->setEmail('omar.passenger@emc.ma');
        $passengerOmar->setFirstName('Omar');
        $passengerOmar->setLastName('Tazi');
        $passengerOmar->setPhone('+212633445566');
        $passengerOmar->setRatingAvg(4.0);
        $passengerOmar->setRatingCount(10);
        $passengerOmar->setPassword($this->passwordHasher->hashPassword($passengerOmar, 'pass123'));
        $this->entityManager->persist($passengerOmar);

        $io->note('Created 6 demo users (1 Admin, 2 Drivers, 3 Passengers).');

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

        // 5. Trips
        // TRIP 1: Multi-stop: Casablanca -> Marrakech -> Agadir (Scheduled for tomorrow)
        $trip1 = new Trip();
        $trip1->setDriver($driverKarim);
        $trip1->setVehicle($vehicleLogan);
        $trip1->setTotalSeats(4);
        $trip1->setStatus(Trip::STATUS_SCHEDULED);
        $depTime1 = new \DateTimeImmutable('+1 day 08:00');
        $trip1->setDepartureTime($depTime1);

        $wp1_0 = new Waypoint();
        $wp1_0->setTrip($trip1);
        $wp1_0->setCity($cities['Casablanca']);
        $wp1_0->setStopOrder(0);
        $wp1_0->setArrivalTime($depTime1);
        $wp1_0->setDistanceFromOriginKm(0.0);

        $wp1_1 = new Waypoint();
        $wp1_1->setTrip($trip1);
        $wp1_1->setCity($cities['Marrakech']);
        $wp1_1->setStopOrder(1);
        $wp1_1->setArrivalTime($depTime1->modify('+3 hours 15 minutes'));
        $wp1_1->setDistanceFromOriginKm(240.0);

        $wp1_2 = new Waypoint();
        $wp1_2->setTrip($trip1);
        $wp1_2->setCity($cities['Agadir']);
        $wp1_2->setStopOrder(2);
        $wp1_2->setArrivalTime($depTime1->modify('+6 hours 45 minutes'));
        $wp1_2->setDistanceFromOriginKm(500.0);

        $trip1->addWaypoint($wp1_0);
        $trip1->addWaypoint($wp1_1);
        $trip1->addWaypoint($wp1_2);
        $this->entityManager->persist($trip1);

        // Booking on Trip 1: Youssef travels Casablanca -> Marrakech only
        $distBooking1 = 240.0;
        $priceBooking1 = $this->pricingCalculator->calculateSegmentPrice($distBooking1, $driverKarim->getRatingAvg(), $pricingConfig);

        $booking1 = new Booking();
        $booking1->setTrip($trip1);
        $booking1->setPassenger($passengerYoussef);
        $booking1->setBoardWaypoint($wp1_0);
        $booking1->setAlightWaypoint($wp1_1);
        $booking1->setSeatsCount(1);
        $booking1->setDistanceKm($distBooking1);
        $booking1->setPrice($priceBooking1);
        $booking1->setStatus(Booking::STATUS_CONFIRMED);

        $payment1 = new Payment();
        $payment1->setBooking($booking1);
        $payment1->setAmount($priceBooking1);
        $payment1->setStatus(Payment::STATUS_COMPLETED);
        $payment1->setPaidAt(new \DateTimeImmutable('-2 hours'));
        $booking1->setPayment($payment1);

        $this->entityManager->persist($booking1);
        $this->entityManager->persist($payment1);

        // TRIP 2: Tangier -> Rabat -> Casablanca (Scheduled for in 3 days)
        $trip2 = new Trip();
        $trip2->setDriver($driverSamira);
        $trip2->setVehicle($vehicleClio);
        $trip2->setTotalSeats(4);
        $trip2->setStatus(Trip::STATUS_SCHEDULED);
        $depTime2 = new \DateTimeImmutable('+3 days 09:00');
        $trip2->setDepartureTime($depTime2);

        $wp2_0 = new Waypoint();
        $wp2_0->setTrip($trip2);
        $wp2_0->setCity($cities['Tangier']);
        $wp2_0->setStopOrder(0);
        $wp2_0->setArrivalTime($depTime2);
        $wp2_0->setDistanceFromOriginKm(0.0);

        $wp2_1 = new Waypoint();
        $wp2_1->setTrip($trip2);
        $wp2_1->setCity($cities['Rabat']);
        $wp2_1->setStopOrder(1);
        $wp2_1->setArrivalTime($depTime2->modify('+2 hours 30 minutes'));
        $wp2_1->setDistanceFromOriginKm(250.0);

        $wp2_2 = new Waypoint();
        $wp2_2->setTrip($trip2);
        $wp2_2->setCity($cities['Casablanca']);
        $wp2_2->setStopOrder(2);
        $wp2_2->setArrivalTime($depTime2->modify('+4 hours'));
        $wp2_2->setDistanceFromOriginKm(340.0);

        $trip2->addWaypoint($wp2_0);
        $trip2->addWaypoint($wp2_1);
        $trip2->addWaypoint($wp2_2);
        $this->entityManager->persist($trip2);

        // Booking on Trip 2: Fatima books 2 seats for the entire trip (Tangier -> Casablanca)
        $distBooking2 = 340.0;
        $unitPrice2 = $this->pricingCalculator->calculateSegmentPrice($distBooking2, $driverSamira->getRatingAvg(), $pricingConfig);
        $totalPrice2 = $unitPrice2 * 2;

        $booking2 = new Booking();
        $booking2->setTrip($trip2);
        $booking2->setPassenger($passengerFatima);
        $booking2->setBoardWaypoint($wp2_0);
        $booking2->setAlightWaypoint($wp2_2);
        $booking2->setSeatsCount(2);
        $booking2->setDistanceKm($distBooking2);
        $booking2->setPrice($totalPrice2);
        $booking2->setStatus(Booking::STATUS_CONFIRMED);

        $payment2 = new Payment();
        $payment2->setBooking($booking2);
        $payment2->setAmount($totalPrice2);
        $payment2->setStatus(Payment::STATUS_COMPLETED);
        $payment2->setPaidAt(new \DateTimeImmutable('-1 hour'));
        $booking2->setPayment($payment2);

        $this->entityManager->persist($booking2);
        $this->entityManager->persist($payment2);

        // TRIP 3: Completed trip with two-way reviews (Marrakech -> Essaouira)
        $trip3 = new Trip();
        $trip3->setDriver($driverKarim);
        $trip3->setVehicle($vehicleLogan);
        $trip3->setTotalSeats(4);
        $trip3->setStatus(Trip::STATUS_COMPLETED);
        $depTime3 = new \DateTimeImmutable('-3 days 10:00');
        $trip3->setDepartureTime($depTime3);

        $wp3_0 = new Waypoint();
        $wp3_0->setTrip($trip3);
        $wp3_0->setCity($cities['Marrakech']);
        $wp3_0->setStopOrder(0);
        $wp3_0->setArrivalTime($depTime3);
        $wp3_0->setDistanceFromOriginKm(0.0);

        $wp3_1 = new Waypoint();
        $wp3_1->setTrip($trip3);
        $wp3_1->setCity($cities['Essaouira']);
        $wp3_1->setStopOrder(1);
        $wp3_1->setArrivalTime($depTime3->modify('+2 hours 45 minutes'));
        $wp3_1->setDistanceFromOriginKm(180.0);

        $trip3->addWaypoint($wp3_0);
        $trip3->addWaypoint($wp3_1);
        $this->entityManager->persist($trip3);

        $distBooking3 = 180.0;
        $priceBooking3 = $this->pricingCalculator->calculateSegmentPrice($distBooking3, $driverKarim->getRatingAvg(), $pricingConfig);

        $booking3 = new Booking();
        $booking3->setTrip($trip3);
        $booking3->setPassenger($passengerOmar);
        $booking3->setBoardWaypoint($wp3_0);
        $booking3->setAlightWaypoint($wp3_1);
        $booking3->setSeatsCount(1);
        $booking3->setDistanceKm($distBooking3);
        $booking3->setPrice($priceBooking3);
        $booking3->setStatus(Booking::STATUS_COMPLETED);

        $payment3 = new Payment();
        $payment3->setBooking($booking3);
        $payment3->setAmount($priceBooking3);
        $payment3->setStatus(Payment::STATUS_COMPLETED);
        $payment3->setPaidAt($depTime3->modify('-1 day'));
        $booking3->setPayment($payment3);

        $this->entityManager->persist($booking3);
        $this->entityManager->persist($payment3);

        // Mutual reviews on completed trip 3:
        // Omar rates Karim
        $review1 = new Review();
        $review1->setTrip($trip3);
        $review1->setAuthor($passengerOmar);
        $review1->setTarget($driverKarim);
        $review1->setRating(5);
        $review1->setComment('Very smooth ride, punctual and careful driver on the road to Essaouira!');
        $this->entityManager->persist($review1);

        // Karim rates Omar
        $review2 = new Review();
        $review2->setTrip($trip3);
        $review2->setAuthor($driverKarim);
        $review2->setTarget($passengerOmar);
        $review2->setRating(5);
        $review2->setComment('Polite and friendly passenger, arrived at the meeting spot right on time.');
        $this->entityManager->persist($review2);

        // Notification for Karim
        $notif1 = new Notification();
        $notif1->setUser($driverKarim);
        $notif1->setTrip($trip1);
        $notif1->setType(Notification::TYPE_BOOKING_CONFIRMED);
        $notif1->setMessage('Youssef Alami booked 1 seat from Casablanca to Marrakech (240 km).');
        $this->entityManager->persist($notif1);

        $this->entityManager->flush();

        $io->success([
            'Demo data successfully seeded!',
            'Login credentials:',
            '  - Admin: admin@emc.ma / admin123',
            '  - Driver 1: karim.driver@emc.ma / driver123',
            '  - Driver 2: samira.driver@emc.ma / driver123',
            '  - Passenger 1: youssef.passenger@emc.ma / pass123',
            '  - Passenger 2: fatima.passenger@emc.ma / pass123',
            '  - Passenger 3: omar.passenger@emc.ma / pass123',
        ]);

        return Command::SUCCESS;
    }
}
