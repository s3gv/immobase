<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use App\Shared\Identity\Uuid;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ein Abrechnungslauf: ein Objekt, ein Wirtschaftsjahr, viele Empfaenger.
 *
 * Der Lauf ist die Arbeitseinheit, das Dokument ist die Post. Kosten und
 * Vorauszahlungen werden **einmal** geprueft und erzeugen alles, was faellig
 * ist — bei Sondereigentumsverwaltung Hausgeldabrechnungen an die Eigentuemer
 * und Nebenkostenabrechnungen an ihre Mieter, in einem Durchgang.
 *
 * Objektnummer und Wirtschaftsjahr stehen eingefroren dabei, obwohl sie sich
 * nachschlagen liessen: sie stecken in der Referenz jedes Schreibens, und die
 * darf sich nicht aendern, weil jemand das Objekt umbenannt hat. Aus
 * demselben Grund steht auch der Zeitraum fest — siehe {@see FiscalPeriod}.
 */
#[ORM\Entity]
#[ORM\Table(name: 'billing_statement')]
#[ORM\UniqueConstraint(name: 'billing_statement_number', columns: ['number', 'iteration'])]
#[ORM\Index(name: 'billing_statement_property', columns: ['property_id', 'fiscal_year'])]
class Statement
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    /** Die sichtbare Nummer des Laufs, ab 1 — sie steckt in jeder Referenz. */
    #[ORM\Column(type: Types::INTEGER)]
    private int $number;

    /** Zaehlt nur bei Korrekturen hoch; das Original ist die 1. */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $iteration = 1;

    #[ORM\Column(name: 'corrects_id', type: Types::GUID, nullable: true)]
    private ?string $correctsId = null;

    #[ORM\Column(name: 'property_id', type: Types::GUID)]
    private string $propertyId;

    #[ORM\Column(name: 'property_number', type: Types::INTEGER)]
    private int $propertyNumber;

    #[ORM\Embedded(class: FiscalPeriod::class, columnPrefix: false)]
    private FiscalPeriod $period;

    #[ORM\Column(type: Types::STRING, length: 200)]
    private string $label = '';

    #[ORM\Embedded(class: StatementKinds::class, columnPrefix: false)]
    private StatementKinds $kinds;

    #[ORM\Embedded(class: Release::class, columnPrefix: false)]
    private Release $release;

    /**
     * Die Entwicklung der Erhaltungsruecklage im Abrechnungsjahr.
     *
     * Sie gehoert zur Jahresabrechnung: die Zufuehrung ist eine Ausgabe der
     * Gemeinschaft, und was mit dem Geld geschah, beantwortet keine Zeile der
     * Ergebnisrechnung. Seit dem WEMoG steht der Vermoegensbericht daneben
     * (§ 28 Abs. 4 WEG) — die Entwicklung im abgerechneten Jahr steht hier,
     * bei den Zahlen, die sie erklaeren.
     *
     * Eingefroren wie alles auf dem Blatt, und aus demselben Grund: ein
     * Auszug, der sich mit der naechsten Entnahme aendert, stuende
     * rueckwirkend anders in der Hand des Empfaengers. Dieselbe Gestalt wie
     * im Vermoegensbericht, damit beide dasselbe sagen.
     */
    #[ORM\Embedded(class: ReportedReserve::class, columnPrefix: false)]
    private ReportedReserve $reserve;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    /** @var Collection<int, StatementDocument> */
    #[ORM\OneToMany(targetEntity: StatementDocument::class, mappedBy: 'statement', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['unitNumber' => 'ASC', 'kind' => 'ASC'])]
    private Collection $documents;

    public function __construct(
        int $number,
        string $propertyId,
        int $propertyNumber,
        FiscalPeriod $period,
    ) {
        $this->id = Uuid::v4();
        $this->number = $number;
        $this->propertyId = $propertyId;
        $this->propertyNumber = $propertyNumber;
        $this->period = $period;
        $this->kinds = StatementKinds::both();
        $this->release = Release::pending();
        $this->reserve = ReportedReserve::nothing();
        $this->createdAt = new DateTimeImmutable();
        $this->documents = new ArrayCollection();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function reserve(): ReportedReserve
    {
        return $this->reserve;
    }

    /** Mit der Freigabe steht der Auszug fest. */
    public function reserveStood(ReportedReserve $reserve): void
    {
        $this->reserve = $reserve;
    }

    public function number(): int
    {
        return $this->number;
    }

    public function iteration(): int
    {
        return $this->iteration;
    }

    public function correctsId(): ?string
    {
        return $this->correctsId;
    }

    public function isCorrection(): bool
    {
        return null !== $this->correctsId;
    }

    /** Eine Korrektur behaelt die Nummer und zaehlt die Iteration hoch. */
    public function corrects(self $original): void
    {
        $this->correctsId = $original->id();
        $this->number = $original->number();
        $this->iteration = $original->iteration() + 1;
    }

    public function propertyId(): string
    {
        return $this->propertyId;
    }

    public function propertyNumber(): int
    {
        return $this->propertyNumber;
    }

    public function fiscalYear(): int
    {
        return $this->period->year();
    }

    /** Der abgerechnete Zeitraum, wie er beim Anlegen galt. */
    public function period(): FiscalPeriod
    {
        return $this->period;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function kinds(): StatementKinds
    {
        return $this->kinds;
    }

    public function describe(string $label, StatementKinds $kinds): void
    {
        $this->label = trim($label);
        $this->kinds = $kinds;
    }

    public function release(): Release
    {
        return $this->release;
    }

    public function isDraft(): bool
    {
        return $this->release->isDraft();
    }

    public function releaseOn(DateTimeImmutable $day): void
    {
        $this->release = $this->release->on($day);
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return list<StatementDocument> */
    public function documents(): array
    {
        return array_values($this->documents->toArray());
    }

    public function add(StatementDocument $document): void
    {
        $this->documents->add($document);
    }

    public function clearDocuments(): void
    {
        $this->documents->clear();
    }
}
