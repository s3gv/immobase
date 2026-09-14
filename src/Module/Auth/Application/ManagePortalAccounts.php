<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Application;

use App\Module\Auth\Contract\PortalAccount;
use App\Module\Auth\Contract\PortalAccounts;
use App\Module\Auth\Domain\User;
use App\Module\Auth\Domain\UserRepository;
use App\Shared\Contact\Email;
use InvalidArgumentException;
use RuntimeException;

/**
 * Portalzugaenge, von den Stammdaten aus bedient.
 *
 * Die Konten sind gewoehnliche Konten: derselbe Einladungsweg, dieselben
 * Passwortregeln, derselbe zweite Faktor. Der einzige Unterschied steht am
 * Konto selbst — es spricht fuer eine Partei, und damit ist es keines der
 * Verwaltung.
 */
final readonly class ManagePortalAccounts implements PortalAccounts
{
    public function __construct(
        private UserRepository $users,
        private InviteUser $invite,
    ) {
    }

    public function forParty(string $partyId): ?PortalAccount
    {
        $user = $this->users->forParty($partyId);

        return null === $user ? null : self::brief($user);
    }

    public function reasonAgainst(string $email): ?string
    {
        $given = trim($email);

        if ('' === $given) {
            return 'user.invite.error.required';
        }

        try {
            $address = Email::fromString($given);
        } catch (InvalidArgumentException) {
            return 'user.invite.error.invalid';
        }

        // Der eindeutige Index bleibt der Riegel: zwischen dieser Frage und
        // dem Anlegen passt eine zweite Einladung. Hier geht es darum, dass
        // der gewoehnliche Fall eine Meldung bekommt und keine Fehlerseite.
        return null === $this->users->findByEmail($address) ? null : 'user.invite.error.taken';
    }

    public function inviteFor(string $partyId, string $email): array
    {
        if (null !== $this->users->forParty($partyId)) {
            // Zweimal einladen heisst „noch einmal", nicht „noch eines".
            return $this->inviteAgain($partyId);
        }

        $invitation = $this->invite->forParty(Email::fromString($email), $partyId);

        return [
            'account' => self::brief($invitation->user),
            'link' => $invitation->link,
            'wasSent' => $invitation->wasSent,
        ];
    }

    public function inviteAgain(string $partyId): array
    {
        $user = $this->users->forParty($partyId)
            ?? throw new RuntimeException('Diese Partei hat keinen Portalzugang.');

        // Ein entzogener Zugang wird beim erneuten Einladen wieder gangbar —
        // sonst muesste man ihn erst „reaktivieren" und dann einladen, und
        // niemand faende den ersten Knopf.
        $user->reactivate();
        $this->users->save($user);

        $invitation = $this->invite->again($user);

        return [
            'account' => self::brief($invitation->user),
            'link' => $invitation->link,
            'wasSent' => $invitation->wasSent,
        ];
    }

    public function revokeFor(string $partyId): void
    {
        $user = $this->users->forParty($partyId);

        if (null === $user) {
            return;
        }

        $user->deactivate();
        $this->users->save($user);
    }

    public function removeFor(string $partyId): void
    {
        $user = $this->users->forParty($partyId);

        if (null !== $user) {
            $this->users->remove($user);
        }
    }

    private static function brief(User $user): PortalAccount
    {
        return new PortalAccount(
            id: $user->id(),
            email: $user->email()->toString(),
            statusKey: $user->status()->labelKey(),
            canSignIn: $user->canSignIn(),
            isRevoked: $user->status()->isBlocked(),
            lastSignInAt: $user->lastSignInAt(),
        );
    }
}
