<?php

namespace App\Controller;

use App\Entity\Booking;
use App\Entity\City;
use App\Entity\Trip;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Entity\Waypoint;
use App\Repository\CityRepository;
use App\Repository\TripRepository;
use App\Repository\UserRepository;
use App\Repository\VehicleRepository;
use App\Routing\RoutingService;
use App\Routing\RoutingUnavailableException;
use App\Routing\StopEta;
use App\Routing\TripRouteUpdater;
use App\Service\PricingCalculator;
use App\Service\ReviewService;
use App\Service\SeatAvailabilityService;
use App\Service\TripModificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/driver')]
class DriverController extends AbstractController
{
    #[Route('', name: 'driver_dashboard')]
    public function index(TripRepository $tripRepo, SeatAvailabilityService $seatService): Response
    {
        /** @var User $driver */
        $driver = $this->getUser();
        $trips = $tripRepo->findBy(['driver' => $driver], ['departureTime' => 'DESC']);

        $tripsData = [];
        $totalPassengers = 0;

        foreach ($trips as $trip) {
            $breakdown = $seatService->getLegOccupancyBreakdown($trip);
            $confirmedBookings = $trip->getBookings()->filter(
                static fn(Booking $b) => in_array($b->getStatus(), [Booking::STATUS_CONFIRMED, Booking::STATUS_COMPLETED], true)
            );

            foreach ($confirmedBookings as $b) {
                $totalPassengers += $b->getSeatsCount();
            }

            $tripsData[] = [
                'trip' => $trip,
                'breakdown' => $breakdown,
                'confirmedCount' => count($confirmedBookings),
            ];
        }

        return $this->render('driver/dashboard.html.twig', [
            'driver' => $driver,
            'tripsData' => $tripsData,
            'totalPassengers' => $totalPassengers,
        ]);
    }

    #[Route('/route-preview', name: 'driver_route_preview', methods: ['POST'])]
    public function routePreview(
        Request $request,
        CityRepository $cityRepo,
        RoutingService $routingService
    ): JsonResponse {
        $content = $request->getContent();
        $data = json_decode($content, true);
        if (!is_array($data)) {
            $data = $request->request->all();
        }

        $originId = (int) ($data['origin_city_id'] ?? 0);
        $destId = (int) ($data['dest_city_id'] ?? 0);
        $intermediateIds = (array) ($data['intermediate_city_ids'] ?? []);

        if ($originId <= 0 || $destId <= 0) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Please select both origin and destination cities.',
            ], Response::HTTP_BAD_REQUEST);
        }

        if ($originId === $destId) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Origin and destination cities cannot be the same.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $originCity = $cityRepo->find($originId);
        $destCity = $cityRepo->find($destId);

        if (!$originCity || !$destCity) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Selected city not found.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $cities = [$originCity];
        foreach ($intermediateIds as $id) {
            $idInt = (int) $id;
            if ($idInt > 0) {
                $city = $cityRepo->find($idInt);
                if ($city) {
                    $cities[] = $city;
                }
            }
        }
        $cities[] = $destCity;

        $points = [];
        foreach ($cities as $city) {
            if ($city->getLatitude() === null || $city->getLongitude() === null) {
                return new JsonResponse([
                    'success' => false,
                    'error' => sprintf('Coordinates missing for city "%s".', $city->getName()),
                ], Response::HTTP_BAD_REQUEST);
            }
            $points[] = [
                'lat' => $city->getLatitude(),
                'lon' => $city->getLongitude(),
            ];
        }

        try {
            $routeResult = $routingService->computeRoute($points);
        } catch (RoutingUnavailableException $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'OpenRouteService unavailable: ' . $e->getMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Routing calculation failed: ' . $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }

        $etas = $routeResult->etas;
        $stopsData = [];

        foreach ($cities as $idx => $city) {
            $eta = $etas[$idx] ?? new StopEta(0.0, 0);
            $intermediateCount = max(0, $idx - 1);
            $bufferedDuration = TripRouteUpdater::calculateBufferedDuration($eta->durationMin, $intermediateCount);
            $formattedEta = TripRouteUpdater::formatDisplayEta($eta->distanceKm, $eta->durationMin, $intermediateCount);
            $formattedDuration = TripRouteUpdater::formatDuration($bufferedDuration);

            $stopsData[] = [
                'index' => $idx,
                'cityId' => $city->getId(),
                'cityName' => $city->getName(),
                'distanceKm' => $eta->distanceKm,
                'rawDurationMin' => $eta->durationMin,
                'bufferedDurationMin' => $bufferedDuration,
                'formattedEta' => ($idx === 0) ? '0 km (Departure)' : $formattedEta,
                'formattedDuration' => $formattedDuration,
            ];
        }

        $destinationEta = end($stopsData);
        $totalDistanceKm = $destinationEta ? $destinationEta['distanceKm'] : 0.0;
        $totalRawDurationMin = $destinationEta ? $destinationEta['rawDurationMin'] : 0;
        $totalBufferedDurationMin = $destinationEta ? $destinationEta['bufferedDurationMin'] : 0;
        $totalFormattedEta = $destinationEta ? $destinationEta['formattedEta'] : '';

        return new JsonResponse([
            'success' => true,
            'totalDistanceKm' => $totalDistanceKm,
            'totalDurationMin' => $totalRawDurationMin,
            'totalBufferedDurationMin' => $totalBufferedDurationMin,
            'totalFormattedEta' => $totalFormattedEta,
            'stops' => $stopsData,
            'geometry' => $routeResult->geometry,
        ]);
    }

    #[Route('/trips/new', name: 'driver_trip_new', methods: ['GET', 'POST'])]
    public function newTrip(
        Request $request,
        CityRepository $cityRepo,
        EntityManagerInterface $em,
        PricingCalculator $pricingCalc,
        TripRouteUpdater $tripRouteUpdater
    ): Response {
        /** @var User $driver */
        $driver = $this->getUser();

        if ($driver->getVehicles()->isEmpty()) {
            $this->addFlash('error', 'You must register a vehicle before publishing a ride.');
            return $this->redirectToRoute('driver_vehicles');
        }

        $cities = $cityRepo->findBy([], ['name' => 'ASC']);

        if ($request->isMethod('POST')) {
            $vehicleId = (int) $request->request->get('vehicle_id');
            $vehicle = $em->getRepository(Vehicle::class)->find($vehicleId);

            if (!$vehicle || $vehicle->getOwner()->getId() !== $driver->getId()) {
                $this->addFlash('error', 'Please select a valid vehicle you own.');
                return $this->render('driver/trip_new.html.twig', ['cities' => $cities, 'vehicles' => $driver->getVehicles()]);
            }

            $originCityId = (int) $request->request->get('origin_city_id');
            $destCityId = (int) $request->request->get('dest_city_id');
            $depDate = (string) $request->request->get('departure_date');
            $depTime = (string) $request->request->get('departure_time');
            $totalSeats = (int) $request->request->get('total_seats', 3);

            $originCity = $cityRepo->find($originCityId);
            $destCity = $destCityId ? $cityRepo->find($destCityId) : null;

            if (!$originCity || !$destCity || empty($depDate) || empty($depTime)) {
                $this->addFlash('error', 'Please fill in all origin and destination details.');
                return $this->render('driver/trip_new.html.twig', ['cities' => $cities, 'vehicles' => $driver->getVehicles()]);
            }

            try {
                $departureDateTime = new \DateTimeImmutable($depDate . ' ' . $depTime);
            } catch (\Exception $e) {
                $this->addFlash('error', 'Invalid departure date/time.');
                return $this->render('driver/trip_new.html.twig', ['cities' => $cities, 'vehicles' => $driver->getVehicles()]);
            }

            $trip = new Trip();
            $trip->setDriver($driver);
            $trip->setVehicle($vehicle);
            $trip->setTotalSeats(max(1, min($vehicle->getSeats() - 1, $totalSeats)));
            $trip->setStatus(Trip::STATUS_SCHEDULED);
            $trip->setDepartureTime($departureDateTime);

            // Origin Waypoint (order 0)
            $wpOrigin = new Waypoint();
            $wpOrigin->setCity($originCity);
            $wpOrigin->setStopOrder(0);
            $wpOrigin->setArrivalTime($departureDateTime);
            $wpOrigin->setDistanceFromOriginKm(0.0);
            $wpOrigin->setDurationFromOriginMin(0);
            $trip->addWaypoint($wpOrigin);

            // Intermediate stops (if provided)
            $stopOrder = 1;
            $interCities = $request->request->all('intermediate_cities');

            if (is_array($interCities)) {
                foreach ($interCities as $cityId) {
                    if (!empty($cityId)) {
                        $interCity = $cityRepo->find((int) $cityId);
                        if ($interCity) {
                            $wpInter = new Waypoint();
                            $wpInter->setCity($interCity);
                            $wpInter->setStopOrder($stopOrder++);
                            $wpInter->setArrivalTime($departureDateTime);
                            $wpInter->setDistanceFromOriginKm(0.0);
                            $trip->addWaypoint($wpInter);
                        }
                    }
                }
            }

            // Destination Waypoint (numbers from browser are intentionally ignored; server recomputes with ORS)
            $wpDest = new Waypoint();
            $wpDest->setCity($destCity);
            $wpDest->setStopOrder($stopOrder);
            $wpDest->setArrivalTime($departureDateTime);
            $wpDest->setDistanceFromOriginKm(0.0);
            $trip->addWaypoint($wpDest);

            $em->persist($trip);
            $em->flush();

            // Server recomputes route values with RoutingService
            $tripRouteUpdater->triggerRefresh($trip);

            $this->addFlash('success', 'Your ride has been successfully published! Passengers can now book seats.');
            return $this->redirectToRoute('driver_dashboard');
        }

        return $this->render('driver/trip_new.html.twig', [
            'cities' => $cities,
            'vehicles' => $driver->getVehicles(),
        ]);
    }

    #[Route('/trips/{id}', name: 'driver_trip_show')]
    public function showTrip(
        Trip $trip,
        SeatAvailabilityService $seatService
    ): Response {
        /** @var User $driver */
        $driver = $this->getUser();
        if ($trip->getDriver()->getId() !== $driver->getId()) {
            throw $this->createAccessDeniedException();
        }

        $breakdown = $seatService->getLegOccupancyBreakdown($trip);
        $canModify = $trip->canBeModified();
        $hoursBeforeDeparture = ($trip->getDepartureTime()->getTimestamp() - (new \DateTimeImmutable())->getTimestamp()) / 3600;

        return $this->render('driver/trip_details.html.twig', [
            'trip' => $trip,
            'breakdown' => $breakdown,
            'canModify' => $canModify,
            'hoursBeforeDeparture' => $hoursBeforeDeparture,
        ]);
    }

    #[Route('/trips/{id}/modify', name: 'driver_trip_modify', methods: ['POST'])]
    public function modifyTrip(
        Trip $trip,
        Request $request,
        TripModificationService $modificationService
    ): Response {
        /** @var User $driver */
        $driver = $this->getUser();
        if ($trip->getDriver()->getId() !== $driver->getId()) {
            throw $this->createAccessDeniedException();
        }

        $depDate = (string) $request->request->get('departure_date');
        $depTime = (string) $request->request->get('departure_time');

        try {
            $newDeparture = new \DateTimeImmutable($depDate . ' ' . $depTime);
            $modificationService->modifyTrip($trip, ['departureTime' => $newDeparture]);
            $this->addFlash('success', 'Trip schedule updated. Passengers have been notified and must confirm their seats.');
        } catch (\DomainException|\Exception $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('driver_trip_show', ['id' => $trip->getId()]);
    }

    #[Route('/trips/{id}/cancel', name: 'driver_trip_cancel', methods: ['POST'])]
    public function cancelTrip(
        Trip $trip,
        TripModificationService $modificationService
    ): Response {
        /** @var User $driver */
        $driver = $this->getUser();
        if ($trip->getDriver()->getId() !== $driver->getId()) {
            throw $this->createAccessDeniedException();
        }

        $hoursBeforeDeparture = ($trip->getDepartureTime()->getTimestamp() - (new \DateTimeImmutable())->getTimestamp()) / 3600;
        $modificationService->cancelTripByDriver($trip);

        if ($hoursBeforeDeparture <= 24) {
            $this->addFlash('warning', 'Trip cancelled within 24 hours of departure. A -1.0 rating penalty was applied to your profile. All passenger reservations were refunded.');
        } else {
            $this->addFlash('success', 'Trip cancelled successfully. All passenger reservations were refunded.');
        }

        return $this->redirectToRoute('driver_dashboard');
    }

    #[Route('/trips/{id}/review-passenger', name: 'driver_review_passenger', methods: ['POST'])]
    public function reviewPassenger(
        Trip $trip,
        Request $request,
        UserRepository $userRepo,
        ReviewService $reviewService
    ): Response {
        /** @var User $driver */
        $driver = $this->getUser();
        if ($trip->getDriver()->getId() !== $driver->getId()) {
            throw $this->createAccessDeniedException();
        }

        $passengerId = (int) $request->request->get('passenger_id');
        $rating = (int) $request->request->get('rating', 5);
        $comment = trim((string) $request->request->get('comment'));

        $passenger = $userRepo->find($passengerId);
        if (!$passenger) {
            $this->addFlash('error', 'Invalid passenger selected.');
            return $this->redirectToRoute('driver_trip_show', ['id' => $trip->getId()]);
        }

        try {
            $reviewService->addReview($trip, $driver, $passenger, $rating, $comment ?: null);
            $this->addFlash('success', 'Your rating for passenger ' . $passenger->getFullName() . ' has been recorded.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('driver_trip_show', ['id' => $trip->getId()]);
    }

    #[Route('/vehicles', name: 'driver_vehicles', methods: ['GET', 'POST'])]
    public function vehicles(Request $request, EntityManagerInterface $em): Response
    {
        /** @var User $driver */
        $driver = $this->getUser();

        if ($request->isMethod('POST')) {
            $brand = trim((string) $request->request->get('brand'));
            $model = trim((string) $request->request->get('model'));
            $plate = strtoupper(trim((string) $request->request->get('plate')));
            $seats = (int) $request->request->get('seats', 4);

            if (empty($brand) || empty($model) || empty($plate)) {
                $this->addFlash('error', 'Please fill in all vehicle fields.');
            } else {
                $vehicle = new Vehicle();
                $vehicle->setOwner($driver);
                $vehicle->setBrand($brand);
                $vehicle->setModel($model);
                $vehicle->setPlate($plate);
                $vehicle->setSeats(max(2, min(9, $seats)));

                $em->persist($vehicle);
                $em->flush();

                $this->addFlash('success', 'Vehicle registered successfully!');
                return $this->redirectToRoute('driver_vehicles');
            }
        }

        return $this->render('driver/vehicles.html.twig', [
            'vehicles' => $driver->getVehicles(),
        ]);
    }
}
