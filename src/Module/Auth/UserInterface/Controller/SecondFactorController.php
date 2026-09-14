<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\UserInterface\Controller;

use App\Module\Auth\Domain\SecondFactor;
use App\Module\Auth\Domain\User;
use App\Module\Auth\UserInterface\Security\PendingSecondFactor;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * Die Seite des zweiten Schritts.
 *
 * Sie zeigt nur — geprueft wird der Code im SecondFactorAuthenticator, damit
 * die Firewall die Anmeldung selbst vornimmt. Ohne wartenden Eintrag in der
 * Sitzung fuehrt die Seite zurueck zur Anmeldung; sie ist kein Weg an der
 * ersten Huerde vorbei.
 */
final class SecondFactorController extends AbstractController
{
    public function __construct(
        private readonly PendingSecondFactor $pending,
        private readonly EmailCodes $codes,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/anmelden/bestaetigen', name: 'app_second_factor', methods: ['GET', 'POST'])]
    public function confirm(Request $request, AuthenticationUtils $errors): Response
    {
        $user = $this->pending->waiting($this->clock->now()->getTimestamp());

        if (null === $user) {
            return $this->redirectToRoute('app_login');
        }

        $failure = $errors->getLastAuthenticationError();
        $message = $this->prepare($user, $request, null !== $failure);

        return $this->render('security/second_factor.html.twig', [
            'kind' => $user->secondFactor()->kind(),
            'message' => $message ?? (null === $failure ? null : $failure->getMessageKey()),
        ]);
    }

    /**
     * Schickt einen Code, wenn er gebraucht wird.
     *
     * Beim Faktor "E-Mail" beim ersten Aufruf und auf Wunsch noch einmal —
     * eine E-Mail kann liegen bleiben, und ohne diesen Knopf bliebe nur, die
     * ganze Anmeldung neu zu beginnen. Nicht nach einem Vertipper: der
     * fuehrt ueber dieselbe Adresse zurueck, und ein neuer Code entwertete
     * den, der gerade im Postfach liegt.
     */
    private function prepare(User $user, Request $request, bool $afterFailure): ?string
    {
        if (SecondFactor::Email !== $user->secondFactor()->kind()) {
            return null;
        }

        if (($request->isMethod('GET') && !$afterFailure) || '' !== $request->request->getString('resend')) {
            return $this->codes->send($user);
        }

        return null;
    }
}
