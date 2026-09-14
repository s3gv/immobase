<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Domain;

use App\Shared\Contact\Email;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Ein Benutzer der Anwendung.
 *
 * Als einzige Klasse dieses Moduls weder readonly noch final: Doctrine braucht
 * veraenderbare Entities und erzeugt Proxy-Klassen.
 *
 * Ein Konto entsteht mit einer Einladung und traegt zu diesem Zeitpunkt nichts
 * ausser Nummer und Adresse. Alles weitere — Passwort, Name, zweiter Faktor —
 * traegt der Eingeladene selbst ein. Wer einlaedt, kennt den Namen oft gar
 * nicht richtig, und geratene Namen bleiben jahrelang stehen.
 */
#[ORM\Entity]
#[ORM\Table(name: 'auth_user')]
#[ORM\UniqueConstraint(name: 'auth_user_email', columns: ['email'])]
#[ORM\UniqueConstraint(name: 'auth_user_number', columns: ['number'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    use CarriesRoles;
    use SignsInWithSymfony;

    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    /**
     * Die sichtbare Nummer, ab 1001 — anderer Zahlenraum als die Stammdaten
     * (ab 10001), damit sich die beiden nicht verwechseln lassen.
     */
    #[ORM\Column(type: Types::INTEGER)]
    private int $number;

    /** @var non-empty-string */
    #[ORM\Column(type: Types::STRING, length: 320)]
    private string $email;

    #[ORM\Embedded(class: AccountState::class, columnPrefix: false)]
    private AccountState $state;

    #[ORM\Embedded(class: SecondFactorSettings::class, columnPrefix: 'factor_')]
    private SecondFactorSettings $factor;

    /**
     * Leer, solange das Konto eingeladen ist. Nullable und nicht der leere
     * String: irgendwann prueft jemand auf `!== ''` statt `!== null`, und
     * ein leerer Hash waere dann ein Passwort.
     */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $password = null;

    #[ORM\Embedded(class: PersonName::class, columnPrefix: false)]
    private PersonName $name;

    #[ORM\Embedded(class: SpeaksFor::class, columnPrefix: false)]
    private SpeaksFor $speaksFor;

    public function __construct(int $number, Email $email, ?string $partyId = null)
    {
        $this->id = bin2hex(random_bytes(16));
        $this->speaksFor = SpeaksFor::orItself($partyId);
        $this->number = $number;
        $this->email = $email->toString();
        $this->name = PersonName::unknown();
        $this->state = AccountState::invited();
        $this->factor = SecondFactorSettings::none();
        $this->roles = new ArrayCollection();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function number(): int
    {
        return $this->number;
    }

    public function email(): Email
    {
        return Email::fromString($this->email);
    }

    /** Nur nach einer Bestaetigung in der neuen Adresse — siehe ChangeEmail. */
    public function changeEmail(Email $email): void
    {
        $this->email = $email->toString();
    }

    public function status(): UserStatus
    {
        return $this->state->status();
    }

    public function name(): PersonName
    {
        return $this->name;
    }

    public function lastSignInAt(): ?DateTimeImmutable
    {
        return $this->state->lastSignInAt();
    }

    /**
     * Abgeleitet und nicht eingegeben: bis der Eingeladene seinen Namen
     * eintraegt, ist die Adresse die einzige ehrliche Bezeichnung.
     */
    public function displayName(): string
    {
        return $this->name->isKnown() ? $this->name->full() : $this->email;
    }

    /** Die eine Frage, an der die ganze Trennung haengt ({@see SpeaksFor}). */
    public function isPortalAccount(): bool
    {
        return $this->speaksFor->isAParty();
    }

    public function partyId(): ?string
    {
        return $this->speaksFor->partyId();
    }

    public function hasPassword(): bool
    {
        return null !== $this->password;
    }

    public function secondFactor(): SecondFactorSettings
    {
        return $this->factor;
    }

    public function useSecondFactor(SecondFactorSettings $factor): void
    {
        $this->factor = $factor;
    }

    /**
     * Nicht deaktiviert und mit Passwort. Ein eingeladenes Konto, das seinen
     * Link eingeloest hat, gehoert ausdruecklich dazu — seine erste
     * Anmeldung ist es, die es aktiv macht.
     */
    public function canSignIn(): bool
    {
        return !$this->state->status()->isBlocked() && $this->hasPassword();
    }

    public function nameYourself(PersonName $name): void
    {
        $this->name = $name;
    }

    public function changePassword(string $hashedPassword): void
    {
        $this->password = $hashedPassword;
    }

    public function signedInAt(DateTimeImmutable $moment): void
    {
        $this->state = $this->state->signedInAt($moment);
    }

    public function activate(): void
    {
        $this->state = $this->state->activated();
    }

    public function deactivate(): void
    {
        $this->state = $this->state->deactivated();
    }

    public function reactivate(): void
    {
        $this->state = $this->state->reactivated($this->hasPassword());
    }
}
