<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\Vehicle;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        // Get the login error if there is one
        $error = $authenticationUtils->getLastAuthenticationError();
        // Last username entered by the user
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): void
    {
        // This controller will be intercepted by the logout key on your firewall
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }

    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $em
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        if ($request->isMethod('POST')) {
            $email = strtolower(trim((string) $request->request->get('email')));
            $firstName = trim((string) $request->request->get('firstName'));
            $lastName = trim((string) $request->request->get('lastName'));
            $phone = trim((string) $request->request->get('phone'));
            $plainPassword = (string) $request->request->get('password');
            $accountType = (string) $request->request->get('accountType'); // 'passenger' or 'driver'

            if (empty($email) || empty($plainPassword) || empty($firstName) || empty($lastName)) {
                $this->addFlash('error', 'Please fill in all required fields.');
                return $this->render('security/register.html.twig');
            }

            // Check if email already exists
            $existing = $em->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($existing !== null) {
                $this->addFlash('error', 'An account with this email already exists.');
                return $this->render('security/register.html.twig');
            }

            $user = new User();
            $user->setEmail($email);
            $user->setFirstName($firstName);
            $user->setLastName($lastName);
            $user->setPhone($phone ?: null);
            $user->setPassword($passwordHasher->hashPassword($user, $plainPassword));

            // Set role based on chosen account type
            if ($accountType === 'driver') {
                $user->addRole(User::ROLE_DRIVER);
            }

            $em->persist($user);
            $em->flush();

            $this->addFlash('success', 'Your account has been created successfully! You can now log in.');
            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/register.html.twig');
    }

    #[Route('/become-driver', name: 'app_become_driver', methods: ['GET', 'POST'])]
    public function becomeDriver(Request $request, EntityManagerInterface $em): Response
    {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        if ($user->isDriver()) {
            return $this->redirectToRoute('driver_dashboard');
        }

        if ($request->isMethod('POST')) {
            $brand = trim((string) $request->request->get('brand'));
            $model = trim((string) $request->request->get('model'));
            $plate = strtoupper(trim((string) $request->request->get('plate')));
            $seats = (int) $request->request->get('seats', 4);

            if (empty($brand) || empty($model) || empty($plate)) {
                $this->addFlash('error', 'Please fill in all vehicle information.');
                return $this->render('security/become_driver.html.twig');
            }

            $vehicle = new Vehicle();
            $vehicle->setOwner($user);
            $vehicle->setBrand($brand);
            $vehicle->setModel($model);
            $vehicle->setPlate($plate);
            $vehicle->setSeats(max(2, min(9, $seats)));

            $user->addRole(User::ROLE_DRIVER);

            $em->persist($vehicle);
            $em->flush();

            $this->addFlash('success', 'Congratulations! You are now registered as a Driver. You can publish your first ride.');
            return $this->redirectToRoute('driver_dashboard');
        }

        return $this->render('security/become_driver.html.twig');
    }
}
