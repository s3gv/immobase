<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\UserInterface\Controller;

use App\Module\Auth\Application\ResetPassword;
use App\Shared\Contact\Email;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Passwort vergessen" — das Formular davor.
 *
 * Die Antwort ist immer dieselbe, ob es das Konto gibt oder nicht. Auch die
 * Antwortzeit: der Versand laeuft im kernel.terminate-Ereignis, also nachdem
 * die Antwort beim Browser ist. Sonst waere der Unterschied zwischen "kurz
 * gesucht" und "E-Mail verschickt" messbar.
 */
final class PasswordResetController extends AbstractController
{
    public function __construct(
        private readonly ResetPassword $reset,
        #[Target('password_reset')]
        private readonly RateLimiterFactoryInterface $passwordResetLimiter,
        #[Target('password_reset_ip')]
        private readonly RateLimiterFactoryInterface $passwordResetIpLimiter,
    ) {
    }

    #[Route('/passwort/vergessen', name: 'app_password_forgotten', methods: ['GET', 'POST'])]
    public function forgotten(Request $request): Response
    {
        if (!$request->isMethod('POST')) {
            return $this->render('security/forgotten.html.twig', ['sent' => false]);
        }

        if (!$this->isCsrfTokenValid('password_forgotten', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }

        $address = $request->request->getString('email');

        // Die Bremse haengt an der Adresse, nicht an der Sitzung: sonst
        // genuegte ein frisches Cookie, um jemanden mit E-Mails zuzudecken.
        // An der Adresse, wie das Konto sie kennt — `ERIKA@example.org` und
        // ` erika@example.org` sind dasselbe Postfach und duerfen nicht je eine
        // eigene Bremse haben. Dazu eine je Absender. Keine aendert die
        // Antwort.
        $accepted = $this->passwordResetLimiter->create(self::keyOf($address))->consume()->isAccepted()
            && $this->passwordResetIpLimiter->create((string) $request->getClientIp())->consume()->isAccepted();

        if ($accepted) {
            $this->reset->request($address);
        }

        return $this->render('security/forgotten.html.twig', ['sent' => true]);
    }

    private static function keyOf(string $address): string
    {
        try {
            return Email::fromString($address)->toString();
        } catch (InvalidArgumentException) {
            return $address;
        }
    }
}
