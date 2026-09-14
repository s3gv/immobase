<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\UserInterface\Controller;

use App\Module\Auth\Domain\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Den zweiten Faktor am eigenen Konto ein- und ausschalten.
 *
 * Getrennt vom uebrigen Konto, weil hier ein Zwischenzustand mitlaeuft: das
 * Geheimnis wartet in der Sitzung, bis ein Code es bestaetigt.
 */
#[IsGranted('ROLE_USER')]
final class AccountFactorController extends AbstractController
{
    public function __construct(
        private readonly SecondFactorSetup $setup,
        private readonly TurnOffSecondFactor $turnOff,
        private readonly AccountPage $page,
    ) {
    }

    #[Route('/mein-konto/faktor', name: 'app_account_factor', methods: ['POST'])]
    public function change(Request $request): Response
    {
        $this->guard($request, 'account_factor');
        $user = $this->me();

        $message = $this->setup->handle($user, $request);

        if (null !== $message) {
            return $this->render(
                'account/faktor.html.twig',
                $this->page->parameters($user, AccountSections::FACTOR, $message),
            );
        }

        $codes = $this->setup->takeRecoveryCodes();
        $back = $this->generateUrl('app_account', ['abschnitt' => AccountSections::FACTOR]);

        if ([] === $codes) {
            return $this->redirect($back);
        }

        // Einmal sichtbar, mit Rueckweg zum Konto.
        return $this->render('invitation/recovery_codes.html.twig', ['codes' => $codes, 'back' => $back]);
    }

    /**
     * Abschalten verlangt einen gueltigen Code.
     *
     * Sonst genuegte ein unbeaufsichtigter Bildschirm, um den zweiten Faktor
     * loszuwerden — und damit die Huerde, die er sein soll.
     */
    #[Route('/mein-konto/faktor/aus', name: 'app_account_factor_off', methods: ['POST'])]
    public function turnOff(Request $request): Response
    {
        $this->guard($request, 'account_factor_off');
        [, $message] = ($this->turnOff)($this->me(), $request);
        $this->addFlash(str_starts_with($message, 'user.factor.error') ? 'error' : 'success', $message);

        return $this->redirectToRoute('app_account', ['abschnitt' => AccountSections::FACTOR]);
    }

    private function guard(Request $request, string $token): void
    {
        if (!$this->isCsrfTokenValid($token, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }
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
