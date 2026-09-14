<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Application;

use App\Module\Auth\Domain\User;
use App\Module\Auth\Domain\UserRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * Haelt fest, dass sich jemand angemeldet hat — und schaltet ein eingeladenes
 * Konto damit frei.
 *
 * Ausdruecklich aufgerufen und nicht an Symfonys Anmeldeereignis gehaengt:
 * bei eingerichtetem zweitem Faktor faellt dieses Ereignis schon nach dem
 * Passwort, und das Konto waere aktiv, bevor der Code stimmt.
 */
final readonly class RecordSignIn
{
    public function __construct(
        private UserRepository $users,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(User $user): void
    {
        $user->signedInAt($this->clock->now());
        $this->users->save($user);
    }
}
