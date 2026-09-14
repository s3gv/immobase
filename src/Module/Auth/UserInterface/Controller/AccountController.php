<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\UserInterface\Controller;

use App\Module\Auth\Application\ChangeEmail;
use App\Module\Auth\Application\ManageSecondFactor;
use App\Module\Auth\Application\SetUpAccount;
use App\Module\Auth\Domain\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Mein Konto" — was jeder an seinem eigenen Konto aendern darf.
 *
 * Zwei Regeln gelten hier und im Einladungsablauf nicht: das Aendern des
 * Passworts verlangt das aktuelle, und das Abschalten des zweiten Faktors
 * einen gueltigen Code. Beides aus demselben Grund — sonst genuegte ein
 * unbeaufsichtigter Bildschirm, um ein Konto zu uebernehmen.
 */
#[IsGranted('ROLE_USER')]
final class AccountController extends AbstractController
{
    public function __construct(
        private readonly SetUpAccount $setUp,
        private readonly ChangeEmail $emails,
        private readonly ManageSecondFactor $factors,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly AccountPage $page,
    ) {
    }

    #[Route('/mein-konto', name: 'app_account', methods: ['GET'])]
    public function account(Request $request): Response
    {
        $section = AccountSections::known($request->query->getString('abschnitt'));

        return $this->render(
            'account/'.$section.'.html.twig',
            $this->page->parameters($this->me(), $section),
        );
    }

    #[Route('/mein-konto/angaben', name: 'app_account_profile', methods: ['POST'])]
    public function profile(Request $request): Response
    {
        $this->guard($request, 'account_profile');

        return $this->back(AccountSections::PROFILE, $this->setUp->describeYourself(
            $this->me(),
            $request->request->getString('givenName'),
            $request->request->getString('familyName'),
            $request->request->getString('jobTitle'),
        ), 'user.account.saved');
    }

    #[Route('/mein-konto/passwort', name: 'app_account_password', methods: ['POST'])]
    public function password(Request $request): Response
    {
        $this->guard($request, 'account_password');
        $user = $this->me();

        // Das aktuelle Passwort zuerst — sonst uebernimmt ein
        // unbeaufsichtigter Bildschirm das Konto.
        if (!$this->hasher->isPasswordValid($user, $request->request->getString('current'))) {
            return $this->back(AccountSections::PASSWORD, 'user.password.error.current', '');
        }

        return $this->back(AccountSections::PASSWORD, $this->setUp->choosePassword(
            $user,
            $request->request->getString('password'),
            $request->request->getString('repeated'),
            notify: true,
        ), 'user.account.password_changed');
    }

    #[Route('/mein-konto/adresse', name: 'app_account_email', methods: ['POST'])]
    public function email(Request $request): Response
    {
        $this->guard($request, 'account_email');
        $user = $this->me();

        if (!$this->hasher->isPasswordValid($user, $request->request->getString('current'))) {
            return $this->back(AccountSections::EMAIL, 'user.password.error.current', '');
        }

        return $this->back(
            AccountSections::EMAIL,
            $this->emails->request($user, $request->request->getString('email')),
            'user.email.requested',
        );
    }

    /**
     * Die Bestaetigung landet in der *neuen* Adresse — deshalb oeffentlich
     * erreichbar: wer sie anklickt, sitzt womoeglich in einem anderen
     * Browser.
     */
    #[Route('/mein-konto/adresse/{token}', name: 'app_account_email_confirm', methods: ['GET'])]
    public function confirmEmail(string $token): Response
    {
        $user = $this->emails->confirm($token);

        if (null === $user) {
            return $this->render('user/link_expired.html.twig', ['purpose' => 'email_change']);
        }

        $this->addFlash('success', 'user.email.changed');

        return $this->redirectToRoute('app_login');
    }

    #[Route('/mein-konto/codes', name: 'app_account_recovery_codes', methods: ['POST'])]
    public function recoveryCodes(Request $request): Response
    {
        $this->guard($request, 'account_recovery');
        $user = $this->me();

        if (!$this->hasher->isPasswordValid($user, $request->request->getString('current'))) {
            return $this->back(AccountSections::FACTOR, 'user.password.error.current', '');
        }

        return $this->render('invitation/recovery_codes.html.twig', [
            'codes' => $this->factors->freshRecoveryCodes($user),
            'back' => $this->generateUrl('app_account', ['abschnitt' => AccountSections::FACTOR]),
        ]);
    }

    private function guard(Request $request, string $token): void
    {
        if (!$this->isCsrfTokenValid($token, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }
    }

    private function back(string $section, ?string $error, string $success): Response
    {
        $this->addFlash(null === $error ? 'success' : 'error', $error ?? $success);

        return $this->redirectToRoute('app_account', ['abschnitt' => $section]);
    }

    private function me(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Kein angemeldetes Konto.');
        }

        return $user;
    }
}
