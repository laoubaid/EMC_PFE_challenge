<?php

namespace App\Controller;

use App\Entity\Trip;
use App\Repository\CityRepository;
use App\Repository\TripRepository;
use App\Repository\UserRepository;
use App\Service\SeatAvailabilityService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(
        CityRepository $cityRepo,
        TripRepository $tripRepo,
        UserRepository $userRepo,
        SeatAvailabilityService $seatService
    ): Response {
        $cities = $cityRepo->findBy([], ['name' => 'ASC']);

        // Find upcoming scheduled trips
        $upcomingTrips = $tripRepo->findBy(
            ['status' => Trip::STATUS_SCHEDULED],
            ['departureTime' => 'ASC'],
            6
        );

        $tripsData = [];
        foreach ($upcomingTrips as $trip) {
            $origin = $trip->getOriginWaypoint();
            $dest = $trip->getDestinationWaypoint();
            if ($origin && $dest) {
                $availableSeats = $seatService->getAvailableSeatsForSegment($trip, $origin, $dest);
                $tripsData[] = [
                    'trip' => $trip,
                    'originCity' => $trip->getOriginCity(),
                    'destinationCity' => $trip->getDestinationCity(),
                    'availableSeats' => $availableSeats,
                ];
            }
        }

        $totalTrips = $tripRepo->count(['status' => Trip::STATUS_SCHEDULED]);
        $totalCities = $cityRepo->count([]);
        $totalUsers = $userRepo->count([]);

        return $this->render('home/index.html.twig', [
            'cities' => $cities,
            'featuredTrips' => $tripsData,
            'totalTrips' => $totalTrips,
            'totalCities' => $totalCities,
            'totalUsers' => $totalUsers,
        ]);
    }
}
