<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\UserInterface\Controller;

use App\Module\Auth\Domain\User;
use App\Module\Auth\Domain\UserRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Holt ein Konto ueber seine Nummer — oder beendet die Anfrage mit 404.
 *
 * Jede Aktion braucht denselben Satz Code davor. Einmal hier statt fuenfmal
 * im Controller.
 */
final readonly class RequireUser
{
    public function __construct(private UserRepository $users)
    {
    }

    public function __invoke(int $number): User
    {
        $user = $this->users->byNumber($number);

        if (null === $user) {
            throw new NotFoundHttpException(\sprintf('Kein Benutzer mit der Nummer %d.', $number));
        }

        return $user;
    }
}
