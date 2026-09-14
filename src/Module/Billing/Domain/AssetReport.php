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
 * Ein Vermoegensbericht: ein Objekt, ein Stichtag, eine Auskunft.
 *
 * Der dritte Bericht neben Abrechnung und Wirtschaftsplan — und der einzige,
 * der **nicht beschlossen** wird. § 28 Abs. 4 WEG verlangt ihn nach Ablauf des
 * Jahres, jeder Eigentuemer kann ihn verlangen, und die Versammlung nimmt ihn
 * zur Kenntnis. Darum traegt er weder Beschluss noch Zahlungsbedingungen: es
 * gibt nichts zu entscheiden und nichts zu zahlen.
 *
 * Zwei Pflichtbausteine, und sie kommen aus zwei Richtungen:
 *
 * * **Der Stand der Erhaltungsruecklage** und die offenen Hausgelder weiss die
 *   Anwendung selbst. Sie werden gerechnet — und mit der Herausgabe
 *   eingefroren, sonst aenderte die naechste Buchung ein zugestelltes
 *   Schreiben.
 * * **Die Aufstellung des Gemeinschaftsvermoegens** weiss sie nicht. Konten,
 *   Verbindlichkeiten und Gegenstaende werden erfasst; eine Buchhaltung, aus
 *   der sie kaemen, gibt es hier nicht, und eine gerechnete Zahl waere eine
 *   erfundene.
 *
 * Unumkehrbar ist er trotzdem: er ist zugestellt worden. Was daran falsch ist,
 * wird in einer **berichtigten Fassung** richtiggestellt.
 */
#[ORM\Entity]
#[ORM\Table(name: 'billing_asset_report')]
#[ORM\UniqueConstraint(name: 'billing_asset_report_number', columns: ['number', 'iteration'])]
#[ORM\Index(name: 'billing_asset_report_property', columns: ['property_id', 'fiscal_year'])]
class AssetReport
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

    #[ORM\Embedded(class: Release::class, columnPrefix: false)]
    private Release $release;

    /** Leer, solange nichts heraus ist — gefuellt wird sie mit der Herausgabe. */
    #[ORM\Embedded(class: ReportedReserve::class, columnPrefix: false)]
    private ReportedReserve $reserve;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    /** @var Collection<int, AssetItem> */
    #[ORM\OneToMany(targetEntity: AssetItem::class, mappedBy: 'report', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['ordering' => 'ASC'])]
    private Collection $items;

    /** @var Collection<int, AssetClaim> */
    #[ORM\OneToMany(targetEntity: AssetClaim::class, mappedBy: 'report', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['unitNumber' => 'ASC'])]
    private Collection $claims;

    /** @var Collection<int, AssetDebt> */
    #[ORM\OneToMany(targetEntity: AssetDebt::class, mappedBy: 'report', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['ordering' => 'ASC'])]
    private Collection $debts;

    /** @var Collection<int, AssetReportDocument> */
    #[ORM\OneToMany(targetEntity: AssetReportDocument::class, mappedBy: 'report', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['unitNumber' => 'ASC'])]
    private Collection $documents;

    public function __construct(int $number, string $propertyId, int $propertyNumber, FiscalPeriod $period)
    {
        $this->id = Uuid::v4();
        $this->edition = Edition::first($number);
        $this->propertyId = $propertyId;
        $this->propertyNumber = $propertyNumber;
        $this->period = $period;
        $this->release = Release::pending();
        $this->reserve = ReportedReserve::nothing();
        $this->createdAt = new DateTimeImmutable();
        $this->items = new ArrayCollection();
        $this->claims = new ArrayCollection();
        $this->debts = new ArrayCollection();
        $this->documents = new ArrayCollection();
    }

    public function id(): string
    {
        return $this->id;
    }

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

    /** Der Stichtag: der letzte Tag des Wirtschaftsjahres. */
    public function asOf(): DateTimeImmutable
    {
        return $this->period->to();
    }

    public function label(): string
    {
        return $this->label;
    }

    public function describe(string $label): void
    {
        $this->label = trim($label);
    }

    public function release(): Release
    {
        return $this->release;
    }

    /**
     * Heraus — mit dem Ruecklagenstand, wie er an diesem Tag ausgerechnet war.
     *
     * Beides in einem Zug: ein Bericht, der als herausgegeben gilt und seinen
     * Stand noch aus den laufenden Buchungen holte, waere genau das, was die
     * Unumkehrbarkeit verhindern soll.
     */
    public function releaseOn(DateTimeImmutable $day, ReportedReserve $reserve): void
    {
        $this->release = $this->release->on($day);
        $this->reserve = $reserve;
    }

    /** Der eingefrorene Ruecklagenstand — bis zur Herausgabe steht hier nichts. */
    public function reserve(): ReportedReserve
    {
        return $this->reserve;
    }

    /** @return list<AssetItem> */
    public function items(): array
    {
        return array_values($this->items->toArray());
    }

    public function add(AssetItem $item): void
    {
        $this->items->add($item);
    }

    public function drop(AssetItem $item): void
    {
        $this->items->removeElement($item);
    }

    /** @return list<AssetClaim> */
    public function claims(): array
    {
        return array_values($this->claims->toArray());
    }

    public function holdClaim(AssetClaim $claim): void
    {
        $this->claims->add($claim);
    }

    /** @return list<AssetDebt> */
    public function debts(): array
    {
        return array_values($this->debts->toArray());
    }

    public function holdDebt(AssetDebt $debt): void
    {
        $this->debts->add($debt);
    }

    /** @return list<AssetReportDocument> */
    public function documents(): array
    {
        return array_values($this->documents->toArray());
    }

    public function hold(AssetReportDocument $document): void
    {
        $this->documents->add($document);
    }
}
