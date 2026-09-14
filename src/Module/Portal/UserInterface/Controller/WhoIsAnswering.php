<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\UserInterface\Controller;

use App\Module\Auth\Contract\AuthenticatedUser;
use App\Module\Auth\Contract\UserDirectory;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Wer gerade antwortet.
 *
 * Ueber den Vertrag der Anmeldung und nicht ueber deren Entity: was an einer
 * Nachricht steht — Name, Berufsbezeichnung, Adresse — ist genau das, was
 * {@see AuthenticatedUser} traegt, und das Abbilden soll nicht an zwei
 * Stellen stehen.
 */
final readonly class WhoIsAnswering
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

        return $found ?? throw new AccessDeniedHttpException('Hier antwortet niemand.');
    }
}
