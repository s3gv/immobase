<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use App\Shared\Identity\Uuid;
use App\Shared\Money\Money;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Eine faellige Vorauszahlung und was davon angekommen ist.
 *
 * **Als bezahlt vorbelegt.** Der Normalfall ist, dass gezahlt wurde; ihn
 * abhaken zu muessen waere zwoelf Klicks im Jahr je Einheit fuer nichts. Wer
 * eine Zahlung vermisst, schaltet um — das ist die Ausnahme und kostet einen
 * Griff.
 *
 * Drei Zustaende, eine Wahrheit:
 *
 * | `settled` | `paid`  | bedeutet              | {@see received()} |
 * |-----------|---------|-----------------------|-------------------|
 * | ja        | —       | wie vereinbart gezahlt| der Sollbetrag    |
 * | nein      | leer    | nichts gezahlt        | null              |
 * | nein      | Betrag  | Teilzahlung           | der Betrag        |
 *
 * Der Teilbetrag wird beim Zurueckschalten geloescht. Bliebe er liegen, kaeme
 * er beim naechsten Umschalten als Zahl wieder hoch, die niemand mehr
 * eingegeben hat.
 *
 * Das ist ausdruecklich **keine Buchhaltung**: kein Zahlungsdatum, keine
 * Zuordnung zu einem Kontoauszug, keine Teilzahlungshistorie. Die Abrechnung
 * braucht eine Zahl — was angekommen ist. Alles Weitere gehoert in das
 * Zahlungsmodul, wenn es kommt.
 */
#[ORM\Entity]
#[ORM\Table(name: 'finance_advance_payment')]
#[ORM\UniqueConstraint(name: 'finance_payment_due', columns: ['unit_id', 'kind', 'due_on', 'reference'])]
#[ORM\Index(name: 'finance_payment_year', columns: ['unit_id', 'fiscal_year'])]
class AdvancePayment
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(name: 'unit_id', type: Types::GUID)]
    private string $unitId;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: AdvanceKind::class)]
    private AdvanceKind $kind;

    #[ORM\Column(name: 'fiscal_year', type: Types::SMALLINT)]
    private int $fiscalYear;

    #[ORM\Column(name: 'due_on', type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $dueOn;

    #[ORM\Column(type: Types::BIGINT)]
    private int $expected;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $settled = true;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $paid = null;

    /**
     * Woraus sie entstanden ist — die Nummer des Beschlusses.
     *
     * Leer bei allem, was aus einer Staffel kommt: Hausgeld und Nebenkosten
     * folgen dem Vertrag und nicht einem Vorgang. Bei einer Sonderumlage ist
     * sie die Antwort auf „wofuer" — und zugleich das, was zwei Umlagen am
     * selben Tag auseinanderhaelt.
     */
    #[ORM\Column(type: Types::STRING, length: 40)]
    private string $reference;

    public function __construct(
        string $unitId,
        AdvanceKind $kind,
        int $fiscalYear,
        DateTimeImmutable $dueOn,
        Money $expected,
        string $reference = '',
    ) {
        $this->id = Uuid::v4();
        $this->unitId = $unitId;
        $this->kind = $kind;
        $this->fiscalYear = $fiscalYear;
        $this->dueOn = $dueOn;
        $this->expected = $expected->cents();
        $this->reference = $reference;
    }

    public function reference(): string
    {
        return $this->reference;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function unitId(): string
    {
        return $this->unitId;
    }

    public function kind(): AdvanceKind
    {
        return $this->kind;
    }

    public function fiscalYear(): int
    {
        return $this->fiscalYear;
    }

    public function dueOn(): DateTimeImmutable
    {
        return $this->dueOn;
    }

    public function expected(): Money
    {
        return Money::fromCents($this->expected);
    }

    /** Die Staffel hat sich geaendert — der Sollbetrag zieht nach. */
    public function expect(Money $expected): void
    {
        $this->expected = $expected->cents();
    }

    /**
     * Der Beschluss hat den Weg gewechselt.
     *
     * Eine berichtigte Fassung kann aus einer Sonderumlage fuer die Massnahme
     * eine zur Ruecklage machen. Dann ist es dieselbe Zahlung an demselben
     * Tag — was jemand ueber sie vermerkt hat, bleibt — und nur ihr Weg ist
     * ein anderer.
     */
    public function becomes(AdvanceKind $kind): void
    {
        $this->kind = $kind;
    }

    public function isSettled(): bool
    {
        return $this->settled;
    }

    /** Der Teilbetrag, falls einer eingetragen wurde. */
    public function part(): ?Money
    {
        return null === $this->paid ? null : Money::fromCents($this->paid);
    }

    /** Was angekommen ist — die einzige Zahl, die die Abrechnung braucht. */
    public function received(): Money
    {
        if ($this->settled) {
            return $this->expected();
        }

        return null === $this->paid ? Money::zero() : Money::fromCents($this->paid);
    }

    public function settle(): void
    {
        $this->settled = true;
        $this->paid = null;
    }

    /** Nicht oder nur teilweise gezahlt. Ohne Betrag heisst: gar nicht. */
    public function missed(?Money $part): void
    {
        $this->settled = false;
        $this->paid = $part?->cents();
    }
}
