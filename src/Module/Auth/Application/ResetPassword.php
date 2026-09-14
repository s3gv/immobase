<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Application;

use App\Module\Auth\Domain\SignInToken;
use App\Module\Auth\Domain\TokenHasher;
use App\Module\Auth\Domain\TokenPurpose;
use App\Module\Auth\Domain\TokenRepository;
use App\Module\Auth\Domain\UserRepository;
use App\Shared\Contact\Email;
use App\Shared\Http\PublicUrls;
use InvalidArgumentException;
use Symfony\Component\Clock\ClockInterface;

/**
 * "Passwort vergessen": schickt einen Link, wenn es das Konto gibt.
 *
 * Der Aufrufer erfaehrt nicht, ob etwas verschickt wurde. Das ist der ganze
 * Punkt: eine Antwort, die zwischen "gibt es" und "gibt es nicht"
 * unterscheidet, macht das Formular zu einer Auskunft darueber, wer hier ein
 * Konto hat.
 */
final readonly class ResetPassword
{
    public function __construct(
        private UserRepository $users,
        private TokenRepository $tokens,
        private Notifier $notifier,
        private TokenHasher $hasher,
        private PublicUrls $urls,
        private ClockInterface $clock,
    ) {
    }

    public function request(string $address): void
    {
        try {
            $email = Email::fromString($address);
        } catch (InvalidArgumentException) {
            return;
        }

        $user = $this->users->findByEmail($email);

        // Ein deaktiviertes Konto bekommt nichts — aber der Absender sieht
        // dieselbe Antwort wie jeder andere.
        if (null === $user || $user->status()->isBlocked()) {
            return;
        }

        $now = $this->clock->now();
        $issued = SignInToken::issue($user->id(), TokenPurpose::Reset, $now, $this->hasher);
        $this->tokens->issue($issued->token, $now);

        $this->notifier->resetPassword($user, $this->urls->absolute('app_password_reset_link', ['token' => $issued->plain]));
    }
}
