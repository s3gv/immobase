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
 * Ein Wirtschaftsplan: ein Objekt, ein Planjahr, je Einheit ein Vorschuss.
 *
 * Die Gegenrichtung zur Abrechnung. Sie schaut zurueck und verteilt, was war;
 * der Plan schaut nach vorn und verteilt, was zu erwarten ist. Gerechnet wird
 * mit denselben Werkzeugen — ein Gesamtbetrag, ein Verteilerschluessel, die
 * Einheiten —, denn ob der Betrag ein Ist oder ein Plan ist, aendert an der
 * Verteilung nichts.
 *
 * Zwei Dinge sind anders, und beide stehen hier:
 *
 * * **Kein Zeitanteil.** Ein Plan gilt fuer das ganze Jahr. Wer im Juli kauft,
 *   uebernimmt den laufenden Vorschuss und bekommt keinen halben Plan.
 * * **Beschlossen werden die Vorschuesse**, nicht der Plan (§ 28 Abs. 1 WEG).
 *   Darum traegt der Plan den Beschluss und die Zahlungsbedingungen und nicht
 *   nur die Zahlen.
 *
 * Objektnummer und Planjahr stehen eingefroren dabei, aus demselben Grund wie
 * bei der Abrechnung: sie stecken in der Referenz jedes Schreibens.
 */
#[ORM\Entity]
#[ORM\Table(name: 'billing_plan')]
#[ORM\UniqueConstraint(name: 'billing_plan_number', columns: ['number', 'iteration'])]
#[ORM\Index(name: 'billing_plan_property', columns: ['property_id', 'fiscal_year'])]
class Plan
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    /** Nummer, Iteration und der Verweis auf die Fassung davor. */
    #[ORM\Embedded(class: Edition::class, columnPrefix: false)]
    private Edition $edition;

    #[ORM\Column(name: 'property_id', type: Types::GUID)]
    private string $propertyId;

    #[ORM\Column(name: 'property_number', type: Types::INTEGER)]
    private int $propertyNumber;

    #[ORM\Embedded(class: FiscalPeriod::class, columnPrefix: false)]
    private FiscalPeriod $period;

    #[ORM\Column(type: Types::STRING, length: 200)]
    private string $label = '';

    #[ORM\Embedded(class: AdvanceTerms::class, columnPrefix: false)]
    private AdvanceTerms $terms;

    #[ORM\Embedded(class: Resolution::class, columnPrefix: false)]
    private Resolution $resolution;

    #[ORM\Embedded(class: ResolutionStage::class, columnPrefix: false)]
    private ResolutionStage $stage;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    /** @var Collection<int, PlanPosition> */
    #[ORM\OneToMany(targetEntity: PlanPosition::class, mappedBy: 'plan', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['ordering' => 'ASC'])]
    private Collection $positions;

    /** @var Collection<int, PlanDocument> */
    #[ORM\OneToMany(targetEntity: PlanDocument::class, mappedBy: 'plan', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['unitNumber' => 'ASC'])]
    private Collection $documents;

    public function __construct(int $number, string $propertyId, int $propertyNumber, FiscalPeriod $period)
    {
        $this->id = Uuid::v4();
        $this->edition = Edition::first($number);
        $this->propertyId = $propertyId;
        $this->propertyNumber = $propertyNumber;
        $this->period = $period;
        $this->terms = AdvanceTerms::monthlyFrom($period->from());
        $this->resolution = Resolution::none();
        $this->stage = ResolutionStage::pending();
        $this->createdAt = new DateTimeImmutable();
        $this->positions = new ArrayCollection();
        $this->documents = new ArrayCollection();
    }

    public function id(): string
    {
        return $this->id;
    }

    /** Die wievielte Fassung welchen Vorgangs — Nummer, Iteration, Vorgaenger. */
    public function edition(): Edition
    {
        return $this->edition;
    }

    public function corrects(self $original): void
    {
        $this->edition = $this->edition->correcting($original->edition, $original->id());
    }

    public function propertyId(): string
    {
        return $this->propertyId;
    }

    public function propertyNumber(): int
    {
        return $this->propertyNumber;
    }

    public function period(): FiscalPeriod
    {
        return $this->period;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function describe(string $label): void
    {
        $this->label = trim($label);
    }

    public function terms(): AdvanceTerms
    {
        return $this->terms;
    }

    public function resolution(): Resolution
    {
        return $this->resolution;
    }

    /** Wie oft und ab wann gezahlt wird. */
    public function payOn(AdvanceTerms $terms): void
    {
        $this->terms = $terms;
    }

    /** Was die Versammlung entschieden hat. */
    public function decide(Resolution $resolution): void
    {
        $this->resolution = $resolution;
    }

    public function stage(): ResolutionStage
    {
        return $this->stage;
    }

    /** Herausgegeben, damit die Versammlung darueber beschliessen kann. */
    public function proposeOn(DateTimeImmutable $day): void
    {
        $this->stage = $this->stage->proposed($day);
    }

    /**
     * Am Inhalt hat sich etwas geaendert.
     *
     * Eine herausgegebene Vorlage ist damit keine mehr — siehe
     * {@see ResolutionStage::revised()}. Was zaehlt, entscheidet
     * {@see PlanContents}.
     *
     * Ihre eingefrorenen Schreiben gehen mit: sie beschreiben eine Vorlage,
     * die es nicht mehr gibt, und ein Blatt ohne Vorlage waere ein Blatt ohne
     * Tag, an dem es vorlag.
     */
    public function revise(): void
    {
        $before = $this->stage;
        $this->stage = $this->stage->revised();

        if ($before !== $this->stage) {
            $this->clearDocuments();
        }
    }

    public function releaseOn(DateTimeImmutable $day): void
    {
        $this->stage = $this->stage->released($day);
    }

    /** @return list<PlanPosition> */
    public function positions(): array
    {
        return array_values($this->positions->toArray());
    }

    public function add(PlanPosition $position): void
    {
        $this->positions->add($position);
    }

    public function drop(PlanPosition $position): void
    {
        $this->positions->removeElement($position);
    }

    /** @return list<PlanDocument> */
    public function documents(): array
    {
        return array_values($this->documents->toArray());
    }

    public function hold(PlanDocument $document): void
    {
        $this->documents->add($document);
    }

    public function clearDocuments(): void
    {
        $this->documents->clear();
    }
}
