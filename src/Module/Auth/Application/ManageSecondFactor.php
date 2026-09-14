<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Application;

use App\Module\Auth\Domain\RecoveryCode;
use App\Module\Auth\Domain\RecoveryCodeRepository;
use App\Module\Auth\Domain\SecondFactorSettings;
use App\Module\Auth\Domain\SignInToken;
use App\Module\Auth\Domain\TokenHasher;
use App\Module\Auth\Domain\TokenPurpose;
use App\Module\Auth\Domain\TokenRepository;
use App\Module\Auth\Domain\Totp;
use App\Module\Auth\Domain\User;
use App\Module\Auth\Domain\UserRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * Einrichten, Pruefen und Abschalten des zweiten Faktors.
 *
 * Eingerichtet wird erst nach einem bestaetigten Code. Ohne diese Bestaetigung
 * traegt jemand ein Geheimnis ein, das seine App nie bekommen hat, und merkt
 * es erst bei der naechsten Anmeldung — dann aber ausgesperrt.
 */
final readonly class ManageSecondFactor
{
    public function __construct(
        private UserRepository $users,
        private TokenRepository $tokens,
        private RecoveryCodeRepository $recoveryCodes,
        private Notifier $notifier,
        private TokenHasher $hasher,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Schaltet den Faktor "App" scharf, wenn der Code stimmt.
     *
     * @return list<string>|null die Wiederherstellungscodes, sonst null
     */
    public function confirmApp(User $user, string $secret, string $code): ?array
    {
        $candidate = SecondFactorSettings::app($secret);
        $accepted = $candidate->accepting($code, $this->clock->now()->getTimestamp());

        if (null === $accepted) {
            return null;
        }

        $user->useSecondFactor($accepted);
        $this->users->save($user);

        return $this->freshRecoveryCodes($user);
    }

    /**
     * Schaltet den Faktor "E-Mail" scharf, wenn der zugeschickte Code stimmt.
     *
     * @return list<string>|null die Wiederherstellungscodes, sonst null
     */
    public function confirmEmail(User $user, string $code): ?array
    {
        if (!$this->acceptsEmailCode($user, $code)) {
            return null;
        }

        $user->useSecondFactor(SecondFactorSettings::email());
        $this->users->save($user);

        return $this->freshRecoveryCodes($user);
    }

    /** Schickt einen Code an die hinterlegte Adresse. */
    public function sendEmailCode(User $user): void
    {
        $now = $this->clock->now();
        $issued = SignInToken::issue($user->id(), TokenPurpose::SecondFactor, $now, $this->hasher);
        $this->tokens->issue($issued->token, $now);

        $this->notifier->secondFactorCode($user, $issued->plain);
    }

    public function acceptsEmailCode(User $user, string $code): bool
    {
        $token = $this->tokens->findUsable($code, TokenPurpose::SecondFactor, $this->clock->now());

        if (null === $token || $token->userId() !== $user->id()) {
            return false;
        }

        return $this->tokens->consume($token, $this->clock->now());
    }

    /**
     * Auch hier entscheidet die Datenbank, wer zuerst da war: das verbrauchte
     * Zeitfenster wird nur dann gesetzt, wenn es noch kleiner war. Sonst
     * kaemen zwei gleichzeitige Anfragen mit demselben Code beide durch.
     */
    public function acceptsAppCode(User $user, string $code): bool
    {
        $accepted = $user->secondFactor()->accepting($code, $this->clock->now()->getTimestamp());

        return null !== $accepted && $this->users->consumeTotpStep($user, $accepted);
    }

    public function acceptsRecoveryCode(User $user, string $code): bool
    {
        $found = $this->recoveryCodes->findUnused($user->id(), $code);

        return null !== $found && $this->recoveryCodes->consume($found, $this->clock->now());
    }

    public function remainingRecoveryCodes(User $user): int
    {
        return $this->recoveryCodes->countUnused($user->id());
    }

    /**
     * @return list<string>
     */
    public function freshRecoveryCodes(User $user): array
    {
        [$codes, $plain] = RecoveryCode::issue($user->id(), $this->hasher);
        $this->recoveryCodes->replaceAll($user->id(), $codes);

        return $plain;
    }

    /** Abschalten nimmt die Wiederherstellungscodes mit — sie gehoeren dazu. */
    public function turnOff(User $user): void
    {
        $user->useSecondFactor(SecondFactorSettings::none());
        $this->users->save($user);
        $this->recoveryCodes->removeAll($user->id());
    }

    public function newAppSecret(): string
    {
        return Totp::newSecret();
    }
}
