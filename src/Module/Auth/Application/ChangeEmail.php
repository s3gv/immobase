<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Application;

use App\Module\Auth\Domain\SignInToken;
use App\Module\Auth\Domain\TokenHasher;
use App\Module\Auth\Domain\TokenPurpose;
use App\Module\Auth\Domain\TokenRepository;
use App\Module\Auth\Domain\User;
use App\Module\Auth\Domain\UserRepository;
use App\Shared\Contact\Email;
use App\Shared\Http\PublicUrls;
use InvalidArgumentException;
use Symfony\Component\Clock\ClockInterface;

/**
 * Die Anmeldeadresse wechseln — in zwei Schritten.
 *
 * Nicht durch Hinschreiben: die Adresse ist die Kennung, an der Anmeldung und
 * alle Schluessel haengen. Ein Tippfehler sperrt das Konto aus, und ein
 * unbeaufsichtigter Bildschirm waere sonst eine Uebernahme.
 *
 * Bestaetigt wird deshalb *in* der neuen Adresse. Die alte bekommt eine
 * Nachricht ohne Link — damit ein stiller Wechsel auffaellt.
 */
final readonly class ChangeEmail
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

    /**
     * @return string|null Uebersetzungsschluessel des Verstosses, sonst null
     */
    public function request(User $user, string $address): ?string
    {
        try {
            $email = Email::fromString($address);
        } catch (InvalidArgumentException) {
            return 'user.email.error.invalid';
        }

        if ($email->toString() === $user->email()->toString()) {
            return 'user.email.error.unchanged';
        }

        if (null !== $this->users->findByEmail($email)) {
            return 'user.email.error.taken';
        }

        $this->send($user, $email);

        return null;
    }

    /**
     * Loest die Bestaetigung ein.
     *
     * Die Adresse wird noch einmal geprueft: zwischen Anfordern und Klick
     * koennen Tage liegen, und in der Zeit kann sie jemand anders bekommen
     * haben.
     */
    public function confirm(string $plain): ?User
    {
        $token = $this->tokens->findUsable($plain, TokenPurpose::EmailChange, $this->clock->now());
        $address = $token?->payload();

        if (null === $token || null === $address) {
            return null;
        }

        $user = $this->users->byId($token->userId());

        if (null === $user || null !== $this->users->findByEmail(Email::fromString($address))) {
            return null;
        }

        // Erst den Schluessel entwerten, dann die Adresse aendern: nur wer
        // die Entwertung gewinnt, darf schreiben. Andersherum aenderten zwei
        // gleichzeitige Klicks beide die Adresse und verschickten beide eine
        // Benachrichtigung.
        return $this->tokens->consume($token, $this->clock->now()) ? $this->moveTo($user, $address) : null;
    }

    private function moveTo(User $user, string $address): User
    {
        $previous = $user->email()->toString();
        $user->changeEmail(Email::fromString($address));
        $this->users->save($user);

        $this->notifier->emailChanged($user, $previous, $address);

        return $user;
    }

    private function send(User $user, Email $email): void
    {
        $now = $this->clock->now();
        $issued = SignInToken::issue($user->id(), TokenPurpose::EmailChange, $now, $this->hasher, $email->toString());
        $this->tokens->issue($issued->token, $now);

        $this->notifier->confirmEmailChange($user, $email->toString(), $this->urls->absolute('app_account_email_confirm', ['token' => $issued->plain]));
    }
}
