<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use App\Shared\Identity\Uuid;
use App\Shared\Money\Money;
use App\Shared\Text\Trimmed;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Eine Bewegung auf der Erhaltungsruecklage.
 *
 * Es gibt keinen Kontodatensatz: das Konto *ist* die Summe seiner
 * Bewegungen. Eine Zeile, die nur sagt „dieses Objekt hat ein Konto", waere
 * bei jedem neuen Objekt ein leerer Datensatz und bei jeder Aenderung der
 * Verwaltungsart eine Frage mehr.
 *
 * Die Ruecklage gehoert der Gemeinschaft (§ 19 Abs. 2 Nr. 4 WEG) — es gibt
 * sie deshalb nur bei WEG-Verwaltung. Bei reiner Mietverwaltung und bei
 * Sondereigentumsverwaltung gibt es keine Gemeinschaftskasse; der
 * SEV-Verwalter zahlt Hausgeld ein, das er nicht fuehrt.
 */
#[ORM\Entity]
#[ORM\Table(name: 'finance_reserve_movement')]
#[ORM\Index(name: 'finance_reserve_property', columns: ['property_id'])]
class ReserveMovement
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(name: 'property_id', type: Types::GUID)]
    private string $propertyId;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: ReserveMovementKind::class)]
    private ReserveMovementKind $kind;

    #[ORM\Column(name: 'occurred_on', type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $occurredOn;

    #[ORM\Column(type: Types::BIGINT)]
    private int $amount;

    /** Nur bei Zufuehrung und Sonderumlage — sonst leer. */
    #[ORM\Column(name: 'unit_id', type: Types::GUID, nullable: true)]
    private ?string $unitId;

    #[ORM\Column(type: Types::TEXT)]
    private string $note = '';

    /**
     * Wann sie storniert wurde — leer heisst: sie gilt.
     *
     * Eine gebuchte Bewegung wird nicht geloescht. Sie kann Grundlage einer
     * Abrechnung sein, und abgerechnet wird das vergangene Jahr, manchmal
     * das vorletzte. Ein Storno nimmt ihr die Wirkung und laesst sie stehen:
     * die Zeile bleibt lesbar, der Bestand stimmt wieder.
     */
    #[ORM\Column(name: 'reversed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $reversedAt = null;

    /** Nicht abgebildet: eine geladene Bewegung steht im Buch. */
    private bool $booked = true;

    public function __construct(
        string $propertyId,
        ReserveMovementKind $kind,
        DateTimeImmutable $occurredOn,
        Money $amount,
        ?string $unitId,
    ) {
        $this->id = Uuid::v4();
        $this->propertyId = $propertyId;
        $this->kind = $kind;
        $this->occurredOn = $occurredOn;
        $this->amount = $amount->cents();
        $this->unitId = $unitId;
    }

    /**
     * Eine Sonderumlage, wie sie auf dem Konto ankam.
     *
     * **Nicht gebucht, sondern gelesen.** Der Beschluss hat je Einheit und
     * Rate eine Zahlung fällig gestellt; was davon ankam, steht dort und
     * nirgends sonst. Diese Bewegung ist der Blick darauf — sie wird nie
     * gespeichert, und darum gibt es an ihr auch nichts zu stornieren:
     * geaendert wird, wo die Zahlung steht.
     *
     * Ein mitgefuehrter Saldo waere eine zweite Wahrheit neben den
     * Bewegungen; eine gebuchte Kopie der Zahlung waere dasselbe eine Ebene
     * tiefer.
     */
    public static function fromALevy(
        string $propertyId,
        string $unitId,
        DateTimeImmutable $receivedOn,
        Money $amount,
    ): self {
        $movement = new self($propertyId, ReserveMovementKind::SpecialLevy, $receivedOn, $amount, $unitId);
        $movement->booked = false;

        return $movement;
    }

    public function id(): string
    {
        return $this->id;
    }

    /**
     * Steht sie im Buch — oder kommt sie aus einem Beschluss?
     *
     * Kein Feld in der Datenbank: was gespeichert ist, ist gebucht. Die
     * abgeleiteten Bewegungen entstehen beim Lesen und tragen den Wert, den
     * ihr benannter Erzeuger setzt.
     */
    public function isBooked(): bool
    {
        return $this->booked;
    }

    public function propertyId(): string
    {
        return $this->propertyId;
    }

    public function kind(): ReserveMovementKind
    {
        return $this->kind;
    }

    public function occurredOn(): DateTimeImmutable
    {
        return $this->occurredOn;
    }

    public function amount(): Money
    {
        return Money::fromCents($this->amount);
    }

    /**
     * Was sie zum Bestand beitraegt — Entnahmen mindern ihn.
     *
     * Eine stornierte traegt nichts bei. Der Bestand bleibt damit die Summe
     * der Bewegungen; was sich aendert, ist die Bewegung und nicht die
     * Rechnung.
     */
    public function effect(): Money
    {
        if ($this->isReversed()) {
            return Money::zero();
        }

        return $this->kind->reducesTheBalance()
            ? Money::zero()->minus($this->amount())
            : $this->amount();
    }

    public function isReversed(): bool
    {
        return null !== $this->reversedAt;
    }

    public function reversedAt(): ?DateTimeImmutable
    {
        return $this->reversedAt;
    }

    /**
     * Stornieren — einmal.
     *
     * Ein zweites Storno waere keine Korrektur mehr, sondern eine Aenderung
     * an der Geschichte.
     *
     * @throws AlreadyReversed
     */
    public function reverse(DateTimeImmutable $on): void
    {
        if ($this->isReversed()) {
            throw new AlreadyReversed();
        }

        $this->reversedAt = $on;
    }

    public function unitId(): ?string
    {
        return $this->unitId;
    }

    public function note(): string
    {
        return $this->note;
    }

    public function noteThat(string $note): void
    {
        $this->note = Trimmed::orNull($note) ?? '';
    }
}
