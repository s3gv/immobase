<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\UserInterface\Controller;

use App\Module\Auth\Application\Invitation;
use App\Module\Auth\Application\InviteUser;
use App\Module\Auth\Application\Rbac\AssignRoles;
use App\Module\Auth\Domain\Rbac\AuthPermissions;
use App\Module\Auth\Domain\Rbac\Role;
use App\Module\Auth\Domain\Rbac\RoleRepository;
use App\Module\Auth\Domain\UserRepository;
use App\Module\Auth\Domain\UserStatus;
use App\Shared\Contact\Email;
use App\Shared\Text\Trimmed;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Einladen — Adresse und Rolle.
 *
 * Mehr braucht es nicht: den Rest traegt der Eingeladene selbst ein. Wer
 * einlaedt, kennt die Schreibweise fremder Namen selten genau, und was hier
 * geraten wird, steht spaeter in jedem Anschreiben.
 *
 * Die Rolle ist trotzdem Pflicht. Sie laesst sich nicht nachtragen, ohne dass
 * jemand daran denkt — und bis dahin stuende das neue Konto vor einer leeren
 * Anwendung.
 */
#[IsGranted(AuthPermissions::USERS_EDIT)]
final class InviteController extends AbstractController
{
    public function __construct(
        private readonly InviteUser $invite,
        private readonly UserRepository $users,
        private readonly RoleRepository $roles,
        private readonly AssignRoles $assign,
        private readonly RequireUser $user,
        private readonly UserPage $page,
    ) {
    }

    #[Route('/benutzer/neu', name: 'app_user_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        if (!$request->isMethod('POST')) {
            return $this->form('', null, []);
        }

        if (!$this->isCsrfTokenValid('user_invite', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }

        $input = Trimmed::orNull($request->request->getString('email')) ?? '';
        $chosen = array_values(array_filter($request->request->all('roles'), \is_string(...)));
        $error = $this->reasonAgainst($input) ?? $this->assign->reasonAgainst($chosen);

        if (null !== $error) {
            return $this->form($input, $error, $chosen);
        }

        return $this->done($this->invite->invite(Email::fromString($input), $this->assign->resolve($chosen)));
    }

    #[Route('/benutzer/{number}/einladen', name: 'app_user_invite_again', requirements: ['number' => '\d+'], methods: ['POST'])]
    public function again(int $number, Request $request): Response
    {
        $user = ($this->user)($number);

        if (!$this->isCsrfTokenValid('user_invite_'.$number, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }

        if (UserStatus::Invited !== $user->status()) {
            $this->addFlash('error', 'user.invite.only_invited');

            return $this->redirectToRoute('app_user_show', ['number' => $number]);
        }

        return $this->done($this->invite->again($user));
    }

    /**
     * Nach dem Einladen auf die Kontoseite — und der Link einmal mit.
     *
     * Er steht in einer Flash-Nachricht und nicht in der Datenbank: gespeichert
     * wird nur sein Hash. Ihn aufzubewahren, um ihn spaeter noch einmal
     * zeigen zu koennen, hiesse einen benutzbaren Schluessel im Klartext zu
     * lagern. Wer ihn verpasst, laedt erneut ein.
     */
    private function done(Invitation $invitation): Response
    {
        $this->addFlash('success', $invitation->wasSent ? 'user.invite.sent' : 'user.invite.no_mailer');

        if (!$invitation->wasSent) {
            $this->addFlash('invite_link', $invitation->link);
        }

        return $this->redirectToRoute('app_user_show', ['number' => $invitation->user->number()]);
    }

    private function reasonAgainst(string $input): ?string
    {
        if ('' === $input) {
            return 'user.invite.error.required';
        }

        try {
            $email = Email::fromString($input);
        } catch (InvalidArgumentException) {
            return 'user.invite.error.invalid';
        }

        return null === $this->users->findByEmail($email) ? null : 'user.invite.error.taken';
    }

    /**
     * @param list<string> $chosen
     */
    private function form(string $email, ?string $error, array $chosen): Response
    {
        $roles = $this->roles->all();

        return $this->render('user/new.html.twig', [
            ...$this->page->invitationSections(),
            'email' => $email,
            'error' => $error,
            'roles' => $roles,
            'chosen' => array_flip($chosen),
            // Gibt es nur die Systemrolle, waere die Auswahl eine Zeile, aus
            // der nur „zum Administrator machen" hervorgeht. Dann steht dort
            // der Hinweis, Rollen anzulegen.
            'onlySystemRole' => [] === array_filter($roles, static fn (Role $r): bool => !$r->isSystem()),
            'trail' => $this->page->trail(null),
        ]);
    }
}
