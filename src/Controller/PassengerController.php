<?php

namespace App\Controller;

use App\Entity\Booking;
use App\Entity\Notification;
use App\Entity\Trip;
use App\Entity\User;
use App\Repository\BookingRepository;
use App\Repository\NotificationRepository;
use App\Service\ReviewService;
use App\Service\TripModificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PassengerController extends AbstractController
{
    #[Route('/my-bookings', name: 'passenger_bookings')]
    public function myBookings(BookingRepository $bookingRepo): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $bookings = $bookingRepo->findBy(['passenger' => $user], ['id' => 'DESC']);

        return $this->render('passenger/bookings.html.twig', [
            'bookings' => $bookings,
        ]);
    }

    #[Route('/my-bookings/{id}/confirm', name: 'passenger_booking_confirm', methods: ['POST'])]
    public function confirmModification(
        Booking $booking,
        TripModificationService $modificationService
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        if ($booking->getPassenger()->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException();
        }

        try {
            $modificationService->passengerConfirmModification($booking);
            $this->addFlash('success', 'You have confirmed the schedule modification for your reservation.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('passenger_bookings');
    }

    #[Route('/my-bookings/{id}/refuse', name: 'passenger_booking_refuse', methods: ['POST'])]
    public function refuseModification(
        Booking $booking,
        TripModificationService $modificationService
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        if ($booking->getPassenger()->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException();
        }

        try {
            $modificationService->passengerRefuseModification($booking);
            $this->addFlash('success', 'You declined the trip modification. A full refund of ' . $booking->getPrice() . ' DH has been credited.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('passenger_bookings');
    }

    #[Route('/my-bookings/{id}/cancel', name: 'passenger_booking_cancel', methods: ['POST'])]
    public function cancelBooking(
        Booking $booking,
        TripModificationService $modificationService
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        if ($booking->getPassenger()->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException();
        }

        if (!$booking->isActive()) {
            $this->addFlash('error', 'Only active reservations can be cancelled.');
            return $this->redirectToRoute('passenger_bookings');
        }

        $modificationService->passengerCancelBooking($booking);
        $this->addFlash('success', 'Your booking has been cancelled and seats were released. A simulated refund of ' . $booking->getPrice() . ' DH was processed.');

        return $this->redirectToRoute('passenger_bookings');
    }

    #[Route('/my-bookings/{id}/review', name: 'passenger_booking_review', methods: ['POST'])]
    public function leaveReview(
        Booking $booking,
        Request $request,
        ReviewService $reviewService
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        if ($booking->getPassenger()->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException();
        }

        $rating = (int) $request->request->get('rating', 5);
        $comment = trim((string) $request->request->get('comment'));

        try {
            $reviewService->addReview($booking->getTrip(), $user, $booking->getTrip()->getDriver(), $rating, $comment ?: null);
            $this->addFlash('success', 'Thank you! Your review has been published for driver ' . $booking->getTrip()->getDriver()->getFullName() . '.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('passenger_bookings');
    }

    #[Route('/notifications', name: 'app_notifications')]
    public function notifications(
        NotificationRepository $notifRepo,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $notifications = $notifRepo->findBy(['user' => $user], ['createdAt' => 'DESC']);

        if ($request->isMethod('POST')) {
            // Mark all as read
            foreach ($notifications as $n) {
                $n->setIsRead(true);
            }
            $em->flush();
            $this->addFlash('success', 'All notifications marked as read.');
            return $this->redirectToRoute('app_notifications');
        }

        return $this->render('passenger/notifications.html.twig', [
            'notifications' => $notifications,
        ]);
    }
}
