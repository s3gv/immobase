<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\UserInterface\Security;

use App\Module\Auth\Domain\User;
use App\Module\Auth\Domain\UserRepository;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Der Zustand zwischen richtigem Passwort und richtigem Code.
 *
 * Er lebt ausschliesslich als Eintrag in der Sitzung — nie als Token im
 * Security-Kontext. Damit gibt es keinen Moment, in dem eine vergessene
 * Pruefung jemanden durchliesse: wer den Code nicht hat, ist schlicht nicht
 * angemeldet.
 *
 * Nach zehn Minuten verfaellt der Eintrag. Ein Bildschirm, an dem die halbe
 * Anmeldung stundenlang offen steht, ist eine Einladung.
 */
final readonly class PendingSecondFactor
{
    private const string KEY = 'auth.pending_second_factor';
    private const int LIFETIME = 600;

    public function __construct(
        private RequestStack $requests,
        private UserRepository $users,
    ) {
    }

    public function remember(User $user, int $now): void
    {
        $this->requests->getSession()->set(self::KEY, ['userId' => $user->id(), 'since' => $now]);
    }

    public function waiting(int $now): ?User
    {
        /** @var array{userId?: string, since?: int}|null $pending */
        $pending = $this->requests->getSession()->get(self::KEY);

        if (null === $pending || !isset($pending['userId'], $pending['since'])) {
            return null;
        }

        if ($now - $pending['since'] > self::LIFETIME) {
            $this->forget();

            return null;
        }

        return $this->users->byId($pending['userId']);
    }

    public function forget(): void
    {
        $this->requests->getSession()->remove(self::KEY);
    }
}
