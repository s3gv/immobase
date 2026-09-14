<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\UserInterface\Controller;

use LogicException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        $failure = $authenticationUtils->getLastAuthenticationError();

        return $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            // Nur der Uebersetzungsschluessel, nicht die Ausnahme: die traegt
            // Klassennamen und Dateipfade, und die Vorlage gibt aus, was sie
            // bekommt.
            'error' => null === $failure ? null : $this->messageFor($failure),
        ]);
    }

    #[Route('/logout', name: 'app_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new LogicException('Diese Methode wird von der Firewall abgefangen und nie ausgeführt.');
    }

    /**
     * Zu viele Versuche bekommen eine eigene Meldung.
     *
     * "E-Mail-Adresse oder Passwort stimmen nicht" waere hier schlicht
     * falsch — womoeglich stimmten sie, und die Bremse hat trotzdem
     * gegriffen. Verraten wird damit nichts: dass es eine Bremse gibt, merkt
     * ohnehin nur, wer sie ausloest.
     */
    private function messageFor(AuthenticationException $failure): string
    {
        return $failure instanceof TooManyLoginAttemptsAuthenticationException
            ? 'login.too_many'
            : 'login.failed';
    }
}
