<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Application;

use App\Module\Auth\Domain\PersonName;
use App\Module\Auth\Domain\TokenPurpose;
use App\Module\Auth\Domain\TokenRepository;
use App\Module\Auth\Domain\User;
use App\Module\Auth\Domain\UserRepository;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Was der Eingeladene an seinem eigenen Konto einstellt.
 *
 * Jeder Schritt speichert fuer sich. Ein abgebrochenes Einrichten hinterlaesst
 * damit ein halbes Konto — und genau das ist gewollt: der naechste Aufruf
 * desselben Links macht dort weiter, statt von vorn zu beginnen.
 */
final readonly class SetUpAccount
{
    public function __construct(
        private UserRepository $users,
        private UserPasswordHasherInterface $hasher,
        private CheckPassword $check,
        private Notifier $notifier,
        private TokenRepository $tokens,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Mit dem neuen Passwort verfallen die offenen Links zum Zuruecksetzen und
     * zum Bestaetigen einer neuen Adresse — auch der, mit dem es gerade
     * gesetzt wurde. Wer einen davon mitgelesen hat, kommt damit nicht mehr
     * an ein Konto, dessen Inhaber laengst ein neues Passwort gewaehlt hat.
     *
     * @return string|null Uebersetzungsschluessel des Verstosses, sonst null
     */
    public function choosePassword(User $user, string $password, string $repeated, bool $notify = false): ?string
    {
        if ($password !== $repeated) {
            return 'user.password.error.mismatch';
        }

        $problem = ($this->check)($password, $this->personalDataOf($user));

        if (null !== $problem) {
            return $problem;
        }

        $user->changePassword($this->hasher->hashPassword($user, $password));
        $this->users->save($user);

        foreach ([TokenPurpose::Reset, TokenPurpose::EmailChange] as $purpose) {
            $this->tokens->revokeOpen($user->id(), $purpose, $this->clock->now());
        }

        if ($notify) {
            $this->notifier->passwordChanged($user);
        }

        return null;
    }

    /**
     * @return string|null Uebersetzungsschluessel des Verstosses, sonst null
     */
    public function describeYourself(User $user, string $givenName, string $familyName, string $jobTitle): ?string
    {
        if ('' === trim($givenName)) {
            return 'user.profile.error.given_name';
        }

        if ('' === trim($familyName)) {
            return 'user.profile.error.family_name';
        }

        $user->nameYourself(PersonName::of($givenName, $familyName, $jobTitle));
        $this->users->save($user);

        return null;
    }

    /**
     * Name und Adresse — daran soll ein Passwort scheitern.
     *
     * @return list<string>
     */
    private function personalDataOf(User $user): array
    {
        return [...$user->name()->parts(), $user->email()->toString()];
    }
}
