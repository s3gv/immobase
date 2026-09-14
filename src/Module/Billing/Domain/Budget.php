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
 * Ein Budgetplan: eine Massnahme und ihre Finanzierung.
 *
 * Die dritte Gestalt neben Abrechnung und Wirtschaftsplan, und sie beantwortet
 * eine andere Frage. Der Wirtschaftsplan plant **ein Jahr** und beschliesst
 * **Vorschuesse**; der Budgetplan plant **eine Massnahme** und beschliesst
 * **ihre Finanzierung**. Ueberlappten sie sich, gaebe es zwei Wahrheiten ueber
 * dasselbe Hausgeld.
 *
 * Zwei Dinge sind hier anders als beim Plan, und beide haben denselben Grund —
 * bei einer baulichen Veraenderung entscheidet die Versammlung nicht nur, *ob*
 * gebaut wird, sondern auch, *wer zahlt* (§ 21 WEG):
 *
 * * Die **Vorlage** zeigt einen Vorschlag samt der Annahme dahinter. Sie kann
 *   nicht wissen, wie abgestimmt wird.
 * * Der **Beschluss** darf den Verteilerkreis aendern. Was er nicht darf, ist
 *   Kosten oder Finanzierung aendern, ohne die Vorlage zurueckzunehmen: was
 *   als Inhalt zaehlt, entscheidet {@see BudgetContents}, und das
 *   Abstimmungsergebnis gehoert nicht dazu — es kommt ja erst danach.
 *
 * Wer zugestimmt hat, steht daneben und nicht darin: {@see BudgetApproval}
 * haengt am Budget wie {@see PlanSource} am Plan. Es ist eine Menge von
 * Kennungen, die einmal nach der Versammlung geschrieben wird — und die
 * Zustimmung ist keine Eigenschaft des Plans, sondern eine Aussage ueber
 * Einheiten.
 */
#[ORM\Entity]
#[ORM\Table(name: 'billing_budget')]
#[ORM\UniqueConstraint(name: 'billing_budget_number', columns: ['number', 'iteration'])]
#[ORM\Index(name: 'billing_budget_property', columns: ['property_id', 'first_year'])]
class Budget
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Embedded(class: Edition::class, columnPrefix: false)]
    private Edition $edition;

    #[ORM\Column(name: 'property_id', type: Types::GUID)]
    private string $propertyId;

    #[ORM\Column(name: 'property_number', type: Types::INTEGER)]
    private int $propertyNumber;

    #[ORM\Embedded(class: Measure::class, columnPrefix: false)]
    private Measure $measure;

    /** Wonach verteilt wird — in aller Regel nach Miteigentumsanteilen. */
    #[ORM\Embedded(class: ChosenKey::class, columnPrefix: false)]
    private ChosenKey $key;

    #[ORM\Embedded(class: Funding::class, columnPrefix: false)]
    private Funding $funding;

    #[ORM\Embedded(class: Resolution::class, columnPrefix: false)]
    private Resolution $resolution;

    #[ORM\Embedded(class: Verdict::class, columnPrefix: false)]
    private Verdict $verdict;

    #[ORM\Embedded(class: ResolutionStage::class, columnPrefix: false)]
    private ResolutionStage $stage;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    /** @var Collection<int, BudgetPosition> */
    #[ORM\OneToMany(targetEntity: BudgetPosition::class, mappedBy: 'budget', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['ordering' => 'ASC'])]
    private Collection $positions;

    /** @var Collection<int, BudgetDocument> die eingefrorenen Schreiben */
    #[ORM\OneToMany(targetEntity: BudgetDocument::class, mappedBy: 'budget', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['unitNumber' => 'ASC'])]
    private Collection $documents;

    public function __construct(int $number, string $propertyId, int $propertyNumber, Measure $measure)
    {
        $this->id = Uuid::v4();
        $this->edition = Edition::first($number);
        $this->propertyId = $propertyId;
        $this->propertyNumber = $propertyNumber;
        $this->measure = $measure;
        $this->key = ChosenKey::none();
        $this->funding = Funding::none();
        $this->resolution = Resolution::none();
        $this->verdict = Verdict::none();
        $this->stage = ResolutionStage::pending();
        $this->createdAt = new DateTimeImmutable();
        $this->positions = new ArrayCollection();
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

    public function measure(): Measure
    {
        return $this->measure;
    }

    public function plan(Measure $measure): void
    {
        $this->measure = $measure;
    }

    public function key(): ChosenKey
    {
        return $this->key;
    }

    public function distributeBy(?string $keyId, string $keyLabel, string $keyKind): void
    {
        $this->key = ChosenKey::of($keyId, $keyLabel, $keyKind);
    }

    public function funding(): Funding
    {
        return $this->funding->levyGoing($this->measure->levyMay($this->funding->levyPurpose()));
    }

    public function fund(Funding $funding): void
    {
        $this->funding = $funding;
    }

    public function resolution(): Resolution
    {
        return $this->resolution;
    }

    /** Was die Versammlung entschieden hat — und wie sie abgestimmt hat. */
    public function decide(Resolution $resolution, int $cast, int $for): void
    {
        $this->resolution = $resolution;
        $this->verdict = $this->verdict->counted($cast, $for);
    }

    public function verdict(): Verdict
    {
        return $this->verdict;
    }

    public function stage(): ResolutionStage
    {
        return $this->stage;
    }

    public function proposeOn(DateTimeImmutable $day): void
    {
        $this->stage = $this->stage->proposed($day);
    }

    /** Am Inhalt hat sich etwas geaendert — die Vorlage ist damit keine mehr. */
    public function revise(): void
    {
        $before = $this->stage;
        $this->stage = $this->stage->revised();

        if ($before !== $this->stage) {
            $this->clearDocuments();
        }
    }

    /** Beschlossen — und damit steht fest, wer traegt. */
    public function releaseOn(DateTimeImmutable $day, CostBearing $bearing): void
    {
        $this->stage = $this->stage->released($day);
        $this->verdict = $this->verdict->bearing($bearing);
    }

    /** @return list<BudgetPosition> */
    public function positions(): array
    {
        return array_values($this->positions->toArray());
    }

    public function add(BudgetPosition $position): void
    {
        $this->positions->add($position);
    }

    public function drop(BudgetPosition $position): void
    {
        $this->positions->removeElement($position);
    }

    /** @return list<BudgetDocument> */
    public function documents(): array
    {
        return array_values($this->documents->toArray());
    }

    public function hold(BudgetDocument $document): void
    {
        $this->documents->add($document);
    }

    public function clearDocuments(): void
    {
        $this->documents->clear();
    }
}
