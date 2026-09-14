<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Domain;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Wo ein Konto in seinem Leben steht — und seit wann.
 *
 * Zustand und letzte Anmeldung gehoeren zusammen: der eine aendert sich durch
 * die andere. Sie hier zu buendeln macht die Uebergaenge an einer Stelle
 * nachlesbar, statt sie ueber die Entity zu verteilen.
 *
 * Unveraenderlich: jeder Uebergang gibt einen neuen Zustand zurueck. Ein
 * halb geaenderter Zustand — Status neu, Zeitpunkt alt — kann so gar nicht
 * erst entstehen.
 */
#[ORM\Embeddable]
final readonly class AccountState
{
    #[ORM\Column(type: Types::STRING, length: 16, enumType: UserStatus::class)]
    private UserStatus $status;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $lastSignInAt;

    private function __construct(UserStatus $status, ?DateTimeImmutable $lastSignInAt)
    {
        $this->status = $status;
        $this->lastSignInAt = $lastSignInAt;
    }

    public static function invited(): self
    {
        return new self(UserStatus::Invited, null);
    }

    public function status(): UserStatus
    {
        return $this->status;
    }

    public function lastSignInAt(): ?DateTimeImmutable
    {
        return $this->lastSignInAt;
    }

    /**
     * Die erste Anmeldung schaltet ein eingeladenes Konto frei.
     *
     * Nicht schon der abgeschlossene Einladungsablauf: erst die Anmeldung
     * belegt, dass Adresse und Passwort zusammengefunden haben.
     */
    public function signedInAt(DateTimeImmutable $moment): self
    {
        return new self(UserStatus::Invited === $this->status ? UserStatus::Active : $this->status, $moment);
    }

    /** Fuer das erste Konto aus der Konsole, das sofort benutzbar sein muss. */
    public function activated(): self
    {
        return new self(UserStatus::Active, $this->lastSignInAt);
    }

    public function deactivated(): self
    {
        return new self(UserStatus::Deactivated, $this->lastSignInAt);
    }

    /**
     * Wer noch nie ein Passwort gesetzt hat, geht zurueck nach "eingeladen".
     *
     * "Aktiv" ohne Passwort waere ein Zustand, der etwas verspricht, das die
     * Anmeldung nicht halten kann.
     */
    public function reactivated(bool $hasPassword): self
    {
        return new self($hasPassword ? UserStatus::Active : UserStatus::Invited, $this->lastSignInAt);
    }
}
