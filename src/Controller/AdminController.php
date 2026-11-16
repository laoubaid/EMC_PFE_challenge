<?php

namespace App\Controller;

use App\Entity\Booking;
use App\Entity\PricingConfig;
use App\Entity\Trip;
use App\Entity\User;
use App\Repository\BookingRepository;
use App\Repository\CityRepository;
use App\Repository\PricingConfigRepository;
use App\Repository\TripRepository;
use App\Repository\UserRepository;
use App\Repository\VehicleRepository;
use App\Service\SeatAvailabilityService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin', name: 'admin_')]
class AdminController extends AbstractController
{
    #[Route('', name: 'dashboard')]
    public function index(
        TripRepository $tripRepo,
        BookingRepository $bookingRepo,
        UserRepository $userRepo,
        VehicleRepository $vehicleRepo,
        PricingConfigRepository $pricingRepo
    ): Response {
        $trips = $tripRepo->findBy([], ['id' => 'DESC'], 5);
        $bookings = $bookingRepo->findBy([], ['id' => 'DESC'], 5);
        $totalTrips = $tripRepo->count([]);
        $totalBookings = $bookingRepo->count([]);
        $totalUsers = $userRepo->count([]);
        $totalVehicles = $vehicleRepo->count([]);
        $activePricing = $pricingRepo->getActiveConfig();

        return $this->render('admin/dashboard.html.twig', [
            'trips' => $trips,
            'bookings' => $bookings,
            'totalTrips' => $totalTrips,
            'totalBookings' => $totalBookings,
            'totalUsers' => $totalUsers,
            'totalVehicles' => $totalVehicles,
            'activePricing' => $activePricing,
        ]);
    }

    #[Route('/trips', name: 'trips')]
    public function trips(TripRepository $tripRepo, SeatAvailabilityService $seatService): Response
    {
        $trips = $tripRepo->findBy([], ['departureTime' => 'DESC']);
        $tripsData = [];

        foreach ($trips as $trip) {
            $tripsData[] = [
                'entity' => $trip,
                'breakdown' => $seatService->getLegOccupancyBreakdown($trip),
            ];
        }

        return $this->render('admin/trips.html.twig', [
            'tripsData' => $tripsData,
        ]);
    }

    #[Route('/trips/{id}/status', name: 'trip_update_status', methods: ['POST'])]
    public function updateTripStatus(Trip $trip, Request $request, EntityManagerInterface $em): Response
    {
        $newStatus = (string) $request->request->get('status');
        $validStatuses = [Trip::STATUS_SCHEDULED, Trip::STATUS_ACTIVE, Trip::STATUS_COMPLETED, Trip::STATUS_CANCELLED];

        if (in_array($newStatus, $validStatuses, true)) {
            $trip->setStatus($newStatus);
            if ($newStatus === Trip::STATUS_CANCELLED) {
                $trip->setCancelledAt(new \DateTimeImmutable());
            }
            $em->flush();
            $this->addFlash('success', sprintf('Trip #%d status updated to "%s".', $trip->getId(), $newStatus));
        }

        return $this->redirectToRoute('admin_trips');
    }

    #[Route('/bookings', name: 'bookings')]
    public function bookings(BookingRepository $bookingRepo): Response
    {
        $bookings = $bookingRepo->findBy([], ['id' => 'DESC']);

        return $this->render('admin/bookings.html.twig', [
            'bookings' => $bookings,
        ]);
    }

    #[Route('/bookings/{id}/status', name: 'booking_update_status', methods: ['POST'])]
    public function updateBookingStatus(Booking $booking, Request $request, EntityManagerInterface $em): Response
    {
        $newStatus = (string) $request->request->get('status');
        $validStatuses = [
            Booking::STATUS_CONFIRMED,
            Booking::STATUS_PENDING_CONFIRMATION,
            Booking::STATUS_CANCELLED_PASSENGER,
            Booking::STATUS_CANCELLED_DRIVER,
            Booking::STATUS_COMPLETED,
        ];

        if (in_array($newStatus, $validStatuses, true)) {
            $booking->setStatus($newStatus);
            $em->flush();
            $this->addFlash('success', sprintf('Booking #%d status updated to "%s".', $booking->getId(), $newStatus));
        }

        return $this->redirectToRoute('admin_bookings');
    }

    #[Route('/pricing', name: 'pricing', methods: ['GET', 'POST'])]
    public function pricing(
        PricingConfigRepository $pricingRepo,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        $configs = $pricingRepo->findBy([], ['effectiveFrom' => 'DESC']);
        $activeConfig = $pricingRepo->getActiveConfig();

        if ($request->isMethod('POST')) {
            $pricePerKm = $request->request->get('pricePerKm');
            $baseMarginPercent = $request->request->get('baseMargin');

            if ($pricePerKm !== null && $baseMarginPercent !== null) {
                $newConfig = new PricingConfig();
                $newConfig->setPricePerKm((float) $pricePerKm);
                $newConfig->setBaseMargin(((float) $baseMarginPercent) / 100);
                $newConfig->setEffectiveFrom(new \DateTimeImmutable());

                /** @var User|null $user */
                $user = $this->getUser();
                if ($user instanceof User) {
                    $newConfig->setUpdatedBy($user);
                }

                $em->persist($newConfig);
                $em->flush();

                $this->addFlash('success', 'New pricing configuration has been activated successfully!');

                return $this->redirectToRoute('admin_pricing');
            }
        }

        return $this->render('admin/pricing.html.twig', [
            'configs' => $configs,
            'activeConfig' => $activeConfig,
        ]);
    }

    #[Route('/users', name: 'users')]
    public function users(UserRepository $userRepo): Response
    {
        $users = $userRepo->findBy([], ['id' => 'ASC']);

        return $this->render('admin/users.html.twig', [
            'users' => $users,
        ]);
    }
}
