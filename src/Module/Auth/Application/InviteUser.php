<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Application;

use App\Module\Auth\Domain\Rbac\Role;
use App\Module\Auth\Domain\SignInToken;
use App\Module\Auth\Domain\TokenHasher;
use App\Module\Auth\Domain\TokenPurpose;
use App\Module\Auth\Domain\TokenRepository;
use App\Module\Auth\Domain\User;
use App\Module\Auth\Domain\UserRepository;
use App\Shared\Contact\Email;
use Symfony\Component\Clock\ClockInterface;

/**
 * Legt ein Konto an und laedt es ein.
 *
 * Zwei Angaben: die Adresse und mindestens eine Rolle. Alles weitere traegt
 * der Eingeladene selbst ein — wer einlaedt, kennt Schreibweisen von Namen
 * oft nicht genau, und geratene Angaben bleiben jahrelang stehen.
 */
final readonly class InviteUser
{
    public function __construct(
        private UserRepository $users,
        private TokenRepository $tokens,
        private Notifier $notifier,
        private TokenHasher $hasher,
        private InvitationLink $link,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Die Rollen sind Pflicht, nicht Beiwerk.
     *
     * Ein Konto ohne Rolle kann nichts: es meldet sich an, sieht eine leere
     * Anwendung, und niemandem faellt auf, warum. Deshalb entsteht es gar
     * nicht erst ohne.
     *
     * @param list<Role> $roles
     *
     * @return Invitation der Link, damit ihn eine Installation ohne
     *                    Mailserver anzeigen kann
     */
    public function invite(Email $email, array $roles): Invitation
    {
        $user = new User($this->users->nextNumber(), $email);
        $user->assignRoles($roles);
        $this->users->save($user);

        return $this->issue($user);
    }

    /**
     * Ein Portalkonto fuer eine Partei — **ohne Rolle**.
     *
     * Der Weg darueber verlangt mindestens eine, und das ist dort richtig: ein
     * Mitarbeiterkonto ohne Rolle sieht eine leere Anwendung, und niemandem
     * faellt auf, warum. Ein Portalkonto sieht das Portal, und das haengt an
     * keiner Rolle, sondern daran, fuer wen es spricht — an einer Rolle haenge
     * es nur so lange, bis jemand ihr `parties.view` mitgibt.
     */
    public function forParty(Email $email, string $partyId): Invitation
    {
        $user = new User($this->users->nextNumber(), $email, $partyId);
        $this->users->save($user);

        return $this->issue($user);
    }

    /**
     * Noch einmal einladen — mit einem frischen Schluessel.
     *
     * Der alte wird dabei entwertet. Sonst haelt eine dreimal angeforderte
     * Einladung drei gueltige Schluessel im Umlauf, und der aelteste ist der,
     * den jemand mitgelesen haben koennte.
     */
    public function again(User $user): Invitation
    {
        return $this->issue($user);
    }

    private function issue(User $user): Invitation
    {
        $now = $this->clock->now();
        $issued = SignInToken::issue($user->id(), TokenPurpose::Invite, $now, $this->hasher);
        $this->tokens->issue($issued->token, $now);

        $link = ($this->link)($issued->plain);
        $this->notifier->invite($user, $link);

        return new Invitation($user, $link, $this->notifier->isConfigured());
    }
}
