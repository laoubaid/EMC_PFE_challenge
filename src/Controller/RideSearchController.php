<?php

namespace App\Controller;

use App\Entity\City;
use App\Entity\Trip;
use App\Entity\User;
use App\Entity\Waypoint;
use App\Repository\CityRepository;
use App\Repository\TripRepository;
use App\Repository\WaypointRepository;
use App\Routing\TripRouteUpdater;
use App\Service\BookingManager;
use App\Service\PricingCalculator;
use App\Service\SeatAvailabilityService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class RideSearchController extends AbstractController
{
    #[Route('/search', name: 'app_search')]
    public function search(
        Request $request,
        CityRepository $cityRepo,
        TripRepository $tripRepo,
        SeatAvailabilityService $seatService,
        PricingCalculator $pricingCalc
    ): Response {
        $cities = $cityRepo->findBy([], ['name' => 'ASC']);

        $originId = $request->query->get('origin');
        $destId = $request->query->get('destination');
        $dateStr = $request->query->get('date');

        $originCity = $originId ? $cityRepo->find($originId) : null;
        $destCity = $destId ? $cityRepo->find($destId) : null;
        $searchDate = $dateStr ? \DateTimeImmutable::createFromFormat('Y-m-d', $dateStr) : null;

        $results = [];

        if ($originCity && $destCity) {
            $matchingTrips = $tripRepo->findTripsBetweenCities($originCity, $destCity, $searchDate ?: null);

            foreach ($matchingTrips as $trip) {
                // Find board and alight waypoints for these cities
                $boardWaypoint = null;
                $alightWaypoint = null;

                foreach ($trip->getOrderedWaypoints() as $wp) {
                    if ($wp->getCity()->getId() === $originCity->getId()) {
                        $boardWaypoint = $wp;
                    } elseif ($boardWaypoint !== null && $wp->getCity()->getId() === $destCity->getId()) {
                        $alightWaypoint = $wp;
                        break;
                    }
                }

                if ($boardWaypoint && $alightWaypoint) {
                    $availableSeats = $seatService->getAvailableSeatsForSegment($trip, $boardWaypoint, $alightWaypoint);
                    $segment = TripRouteUpdater::segment($boardWaypoint, $alightWaypoint);
                    $price = $pricingCalc->calculateSegmentPrice($segment->distanceKm, $trip->getDriver()->getRatingAvg());
                    $intermediateCount = max(0, abs($alightWaypoint->getStopOrder() - $boardWaypoint->getStopOrder()) - 1);
                    $bufferedDuration = TripRouteUpdater::calculateBufferedDuration($segment->durationMin, $intermediateCount);
                    $displayEta = TripRouteUpdater::formatDisplayEta($segment->distanceKm, $segment->durationMin, $intermediateCount);

                    $results[] = [
                        'trip' => $trip,
                        'boardWaypoint' => $boardWaypoint,
                        'alightWaypoint' => $alightWaypoint,
                        'availableSeats' => $availableSeats,
                        'distanceKm' => $segment->distanceKm,
                        'durationMin' => $segment->durationMin,
                        'bufferedDurationMin' => $bufferedDuration,
                        'displayEta' => $displayEta,
                        'price' => $price,
                    ];
                }
            }
        }

        return $this->render('search/index.html.twig', [
            'cities' => $cities,
            'originCity' => $originCity,
            'destCity' => $destCity,
            'searchDate' => $dateStr,
            'results' => $results,
            'hasSearched' => ($originCity !== null && $destCity !== null),
        ]);
    }

    #[Route('/trips/{id}', name: 'app_trip_show')]
    public function show(
        Trip $trip,
        Request $request,
        WaypointRepository $waypointRepo,
        SeatAvailabilityService $seatService,
        PricingCalculator $pricingCalc
    ): Response {
        $orderedWaypoints = $trip->getOrderedWaypoints();

        // Selected board and alight stops (default to origin and destination)
        $boardId = $request->query->getInt('board', $trip->getOriginWaypoint()?->getId() ?? 0);
        $alightId = $request->query->getInt('alight', $trip->getDestinationWaypoint()?->getId() ?? 0);

        $boardWaypoint = $boardId ? $waypointRepo->find($boardId) : $trip->getOriginWaypoint();
        $alightWaypoint = $alightId ? $waypointRepo->find($alightId) : $trip->getDestinationWaypoint();

        $availableSeats = 0;
        $distance = 0.0;
        $durationMin = 0;
        $bufferedDurationMin = 0;
        $displayEta = '';
        $price = 0.0;

        if ($boardWaypoint && $alightWaypoint && $boardWaypoint->getStopOrder() < $alightWaypoint->getStopOrder()) {
            $availableSeats = $seatService->getAvailableSeatsForSegment($trip, $boardWaypoint, $alightWaypoint);
            $segment = TripRouteUpdater::segment($boardWaypoint, $alightWaypoint);
            $distance = $segment->distanceKm;
            $durationMin = $segment->durationMin;
            $price = $pricingCalc->calculateSegmentPrice($distance, $trip->getDriver()->getRatingAvg());
            $intermediateCount = max(0, abs($alightWaypoint->getStopOrder() - $boardWaypoint->getStopOrder()) - 1);
            $bufferedDurationMin = TripRouteUpdater::calculateBufferedDuration($durationMin, $intermediateCount);
            $displayEta = TripRouteUpdater::formatDisplayEta($distance, $durationMin, $intermediateCount);
        }

        $occupancyBreakdown = $seatService->getLegOccupancyBreakdown($trip);

        return $this->render('search/trip_details.html.twig', [
            'trip' => $trip,
            'orderedWaypoints' => $orderedWaypoints,
            'boardWaypoint' => $boardWaypoint,
            'alightWaypoint' => $alightWaypoint,
            'availableSeats' => $availableSeats,
            'distanceKm' => $distance,
            'durationMin' => $durationMin,
            'bufferedDurationMin' => $bufferedDurationMin,
            'displayEta' => $displayEta,
            'price' => $price,
            'occupancyBreakdown' => $occupancyBreakdown,
        ]);
    }

    #[Route('/trips/{id}/book', name: 'app_trip_book', methods: ['POST'])]
    public function book(
        Trip $trip,
        Request $request,
        WaypointRepository $waypointRepo,
        BookingManager $bookingManager
    ): Response {
        /** @var User|null $passenger */
        $passenger = $this->getUser();
        if (!$passenger) {
            $this->addFlash('error', 'Please log in to book a seat.');
            return $this->redirectToRoute('app_login');
        }

        $boardId = (int) $request->request->get('board_waypoint_id');
        $alightId = (int) $request->request->get('alight_waypoint_id');
        $seatsCount = (int) $request->request->get('seats_count', 1);

        $boardWaypoint = $waypointRepo->find($boardId);
        $alightWaypoint = $waypointRepo->find($alightId);

        if (!$boardWaypoint || !$alightWaypoint) {
            $this->addFlash('error', 'Invalid boarding or alighting stop selected.');
            return $this->redirectToRoute('app_trip_show', ['id' => $trip->getId()]);
        }

        try {
            $booking = $bookingManager->createBooking(
                $trip,
                $passenger,
                $boardWaypoint,
                $alightWaypoint,
                $seatsCount
            );

            $this->addFlash('success', sprintf(
                'Reservation confirmed! %d seat(s) booked from %s to %s for %s DH (simulated payment completed).',
                $booking->getSeatsCount(),
                $boardWaypoint->getCity()->getName(),
                $alightWaypoint->getCity()->getName(),
                $booking->getPrice()
            ));

            return $this->redirectToRoute('passenger_bookings');
        } catch (\DomainException|\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('app_trip_show', [
                'id' => $trip->getId(),
                'board' => $boardId,
                'alight' => $alightId,
            ]);
        }
    }
}
