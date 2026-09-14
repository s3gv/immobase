<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Party\UserInterface\Controller;

use App\Module\Auth\Contract\PortalAccount;
use App\Module\Auth\Contract\PortalAccounts;
use App\Module\Party\Domain\PartyPermissions;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Den Portalzugang einer Partei einrichten und entziehen.
 *
 * **Per Vorgabe bekommt niemand einen.** Wer einen Zugang haben soll, wird
 * einzeln freigeschaltet — wer hundert Einladungen auf einmal verschickt,
 * verschickt auch hundert falsche.
 *
 * Die Arbeit macht die Anmeldung ueber {@see PortalAccounts}; dieses Modul
 * kennt `Auth\Domain\User` nicht. Es weiss nur, dass es zu einer Partei einen
 * Zugang geben kann.
 */
#[IsGranted(PartyPermissions::EDIT)]
final class PortalAccessController extends AbstractController
{
    /** Beide Routen haengen an derselben Referenznummer. */
    private const array WHERE = ['reference' => '\d+'];

    public function __construct(
        private readonly RequireParty $party,
        private readonly PortalAccounts $accounts,
    ) {
    }

    /**
     * Einladen — und die Adresse ist Eingabe.
     *
     * Sie kommt zwar aus einem `select`, aber eine abgeschickte Anfrage kommt
     * aus dem Netz: sie kann leer sein, unsinnig, oder eine Adresse tragen,
     * die schon zu einem anderen Konto gehoert. Ob sie taugt, weiss die
     * Anmeldung — gefragt wird, bevor angelegt wird, damit daraus eine
     * Meldung wird und keine Fehlerseite.
     */
    #[Route('/stammdaten/{reference}/portalzugang', name: 'app_party_portal_invite', requirements: self::WHERE, methods: ['POST'])]
    public function invite(int $reference, Request $request): Response
    {
        $party = ($this->party)($reference);
        $this->guard($request, $reference);

        $email = trim($request->request->getString('email'));
        $existing = $this->accounts->forParty($party->id());
        $refused = null === $existing ? $this->accounts->reasonAgainst($email) : null;

        if (null !== $refused) {
            return $this->refused($refused, $reference);
        }

        try {
            $invited = null === $existing
                ? $this->accounts->inviteFor($party->id(), $email)
                : $this->accounts->inviteAgain($party->id());
        } catch (UniqueConstraintViolationException) {
            // Zwischen der Frage und dem Anlegen passt eine zweite Einladung.
            // Der eindeutige Index faengt sie ab; hier wird daraus dieselbe
            // Absage wie oben statt eines Fehlers mitten auf der Seite.
            return $this->refused('user.invite.error.taken', $reference);
        }

        return $this->handedOut($invited, $reference);
    }

    #[Route(
        '/stammdaten/{reference}/portalzugang/entziehen',
        name: 'app_party_portal_revoke',
        requirements: self::WHERE,
        methods: ['POST'],
    )]
    public function revoke(int $reference, Request $request): Response
    {
        $party = ($this->party)($reference);
        $this->guard($request, $reference);

        $this->accounts->revokeFor($party->id());
        $this->addFlash('success', 'party.portal.revoked');

        return $this->back($reference);
    }

    private function refused(string $message, int $reference): Response
    {
        $this->addFlash('error', $message);

        return $this->back($reference);
    }

    /**
     * Ohne Mailserver ist der Link das Einzige, was ankommt — er steht genau
     * einmal zur Verfuegung, danach gibt es nur noch seinen Hash.
     *
     * @param array{account: PortalAccount, link: string, wasSent: bool} $invited
     */
    private function handedOut(array $invited, int $reference): Response
    {
        $this->addFlash(
            'success',
            $invited['wasSent'] ? 'party.portal.invited' : 'party.portal.invited_without_mail',
        );

        if (!$invited['wasSent']) {
            $this->addFlash('info', $invited['link']);
        }

        return $this->back($reference);
    }

    private function back(int $reference): Response
    {
        return $this->redirectToRoute('app_party_show', [
            'reference' => $reference,
            'abschnitt' => 'erreichbarkeit',
        ]);
    }

    private function guard(Request $request, int $reference): void
    {
        if (!$this->isCsrfTokenValid('party_portal_'.$reference, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }
    }
}
