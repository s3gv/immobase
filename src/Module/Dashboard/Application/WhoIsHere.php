<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dashboard\Application;

use App\Module\Auth\Contract\AuthenticatedUser;
use App\Module\Auth\Contract\UserDirectory;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Wer gerade hereingekommen ist.
 *
 * Ueber den Vertrag der Anmeldung und nicht ueber deren Entity.
 * Gebraucht wird der Name fuer die Begruessung und die Kennung fuer die
 * eigenen Erinnerungen.
 */
final readonly class WhoIsHere
{
    public function __construct(
        private Security $security,
        private UserDirectory $users,
    ) {
    }

    public function __invoke(): AuthenticatedUser
    {
        $signedIn = $this->security->getUser();
        $found = null === $signedIn ? null : $this->users->byEmail($signedIn->getUserIdentifier());

        return $found ?? throw new AccessDeniedHttpException('Hier ist niemand.');
    }
}
