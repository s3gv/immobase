<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\UserInterface\Security;

use App\Module\Auth\Domain\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Laesst nur herein, wer sich anmelden darf.
 *
 * Die Meldung ist dieselbe wie bei einem falschen Passwort, und das ist
 * Absicht: "Dieses Konto ist deaktiviert" waere die Auskunft, dass es zu
 * dieser Adresse ueberhaupt ein Konto gibt. Wer hier ein Konto hat, geht
 * niemanden etwas an, der das Formular durchprobiert.
 *
 * Wer wirklich deaktiviert wurde, weiss es von der Person, die es getan hat.
 */
final readonly class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        if (!$user->canSignIn()) {
            throw new CustomUserMessageAccountStatusException('login.failed');
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
    }
}
