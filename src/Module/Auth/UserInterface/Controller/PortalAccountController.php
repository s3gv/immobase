<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\UserInterface\Controller;

use App\Module\Auth\Application\CheckPassword;
use App\Module\Auth\Application\ManageSecondFactor;
use App\Module\Auth\Application\SetUpAccount;
use App\Module\Auth\Domain\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Das eigene Konto — in der Portalhuelle.
 *
 * Eigene Adressen und nicht `/mein-konto`: ein Portalkonto soll keine Adresse
 * des Verwalterbereichs aufrufen koennen, auch keine harmlose. Die Arbeit
 * machen dieselben Anwendungsfaelle wie dort — es gibt keine zweite
 * Passwortpruefung und keinen zweiten zweiten Faktor.
 *
 * **Weniger als drueben, mit Absicht.** Name und Berufsbezeichnung stehen
 * hier nicht: der Name eines Portalnutzers ist der seiner Partei, und der
 * aendert sich ueber einen Vorschlag, nicht ueber die Kontoseite. Eine zweite
 * Stelle dafuer waere eine zweite Wahrheit ueber denselben Menschen.
 */
final class PortalAccountController extends AbstractController
{
    public function __construct(
        private readonly SetUpAccount $setUp,
        private readonly ManageSecondFactor $factors,
        private readonly SecondFactorSetup $setup,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly CheckPassword $rules,
        private readonly TurnOffSecondFactor $turnOff,
    ) {
    }

    #[Route('/portal/konto', name: 'app_portal_account', methods: ['GET'])]
    public function account(): Response
    {
        return $this->page();
    }

    #[Route('/portal/konto/passwort', name: 'app_portal_account_password', methods: ['POST'])]
    public function password(Request $request): Response
    {
        $this->guard($request, 'account_password');
        $user = $this->me();

        // Das aktuelle Passwort zuerst — sonst uebernimmt ein
        // unbeaufsichtigter Bildschirm das Konto.
        if (!$this->hasher->isPasswordValid($user, $request->request->getString('current'))) {
            return $this->back('user.password.error.current', '');
        }

        return $this->back($this->setUp->choosePassword(
            $user,
            $request->request->getString('password'),
            $request->request->getString('repeated'),
            notify: true,
        ), 'user.account.password_changed');
    }

    #[Route('/portal/konto/faktor', name: 'app_portal_account_factor', methods: ['POST'])]
    public function factor(Request $request): Response
    {
        $this->guard($request, 'account_factor');
        $user = $this->me();
        $message = $this->setup->handle($user, $request);

        if (null !== $message) {
            return $this->page($message);
        }

        $codes = $this->setup->takeRecoveryCodes();

        return [] === $codes ? $this->toAccount() : $this->showCodes($codes);
    }

    /**
     * Abschalten verlangt einen gueltigen Code.
     *
     * Sonst genuegte ein unbeaufsichtigter Bildschirm, um die Huerde
     * loszuwerden, die der zweite Faktor sein soll.
     */
    #[Route('/portal/konto/faktor/aus', name: 'app_portal_account_factor_off', methods: ['POST'])]
    public function turnOff(Request $request): Response
    {
        $this->guard($request, 'account_factor_off');
        [, $message] = ($this->turnOff)($this->me(), $request);

        return $this->back(str_starts_with($message, 'user.factor.error') ? $message : null, $message);
    }

    #[Route('/portal/konto/codes', name: 'app_portal_account_codes', methods: ['POST'])]
    public function recoveryCodes(Request $request): Response
    {
        $this->guard($request, 'account_recovery');
        $user = $this->me();

        if (!$this->hasher->isPasswordValid($user, $request->request->getString('current'))) {
            return $this->back('user.password.error.current', '');
        }

        return $this->showCodes($this->factors->freshRecoveryCodes($user));
    }

    /**
     * @param list<string> $codes
     */
    private function showCodes(array $codes): Response
    {
        // Einmal sichtbar, mit Rueckweg ins Konto.
        return $this->render('invitation/recovery_codes.html.twig', [
            'codes' => $codes,
            'back' => $this->generateUrl('app_portal_account'),
        ]);
    }

    private function page(?string $factorMessage = null): Response
    {
        $user = $this->me();

        return $this->render('portal/konto.html.twig', [
            'user' => $user,
            'rules' => $this->rules->rules(),
            'recoveryCodesLeft' => $this->factors->remainingRecoveryCodes($user),
            'factor' => $this->setup->offer(),
            'factorMessage' => $factorMessage,
        ]);
    }

    private function toAccount(): Response
    {
        return $this->redirectToRoute('app_portal_account');
    }

    private function back(?string $error, string $success): Response
    {
        $this->addFlash(null === $error ? 'success' : 'error', $error ?? $success);

        return $this->toAccount();
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
