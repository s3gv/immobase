<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use App\Shared\Identity\Uuid;
use App\Shared\Money\Money;
use App\Shared\Text\Trimmed;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ein Darlehen der Gemeinschaft.
 *
 * **Kein Feld am Objekt, sondern ein Verlauf.** Die Restschuld ist an jedem
 * Tag eine andere, und die Rate zerfaellt in Zins und Tilgung, deren
 * Verhaeltnis sich mit jeder Zahlung verschiebt. Eine gespeicherte Restschuld
 * waere eine Zahl, die jemand jaehrlich nachpflegen muss — und die zweite
 * Wahrheit neben der, die sich rechnen laesst.
 *
 * Gespeichert wird darum nur, was vereinbart wurde: Summe, Zins, erste Rate
 * und die Rate oder die Laufzeit. Alles Weitere ist {@see LoanSchedule} —
 * eine Rechnung ueber diese Angaben und die {@see LoanEvent}s.
 *
 * **Zins ist Aufwand, Tilgung ist es nicht.** Sie schichtet Vermoegen um:
 * die Verbindlichkeit sinkt. Geplant werden muss trotzdem beides, weil
 * beides abfliesst — darum stehen im Wirtschaftsplan zwei Zeilen.
 */
#[ORM\Entity]
#[ORM\Table(name: 'finance_loan')]
#[ORM\UniqueConstraint(name: 'finance_loan_number', columns: ['number'])]
#[ORM\Index(name: 'finance_loan_property', columns: ['property_id'])]
class Loan
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    /** Die sichtbare Nummer, ab 50001 — eigener Zahlenraum je Datenart. */
    #[ORM\Column(type: Types::INTEGER)]
    private int $number;

    #[ORM\Column(name: 'property_id', type: Types::GUID)]
    private string $propertyId;

    #[ORM\Column(type: Types::STRING, length: 200)]
    private string $label = '';

    /** Wer es gegeben hat — die Bank steht auf jedem Kontoauszug. */
    #[ORM\Column(type: Types::STRING, length: 200)]
    private string $lender = '';

    #[ORM\Embedded(class: LoanTerms::class, columnPrefix: false)]
    private LoanTerms $terms;

    /** Aus welchem Beschluss es stammt — leer, wenn es ohne Budgetplan aufgenommen wurde. */
    #[ORM\Column(type: Types::STRING, length: 40)]
    private string $reference = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $note = '';

    /** @var Collection<int, LoanEvent> */
    #[ORM\OneToMany(targetEntity: LoanEvent::class, mappedBy: 'loan', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['occurredOn' => 'ASC'])]
    private Collection $events;

    public function __construct(int $number, string $propertyId, LoanTerms $terms)
    {
        $this->id = Uuid::v4();
        $this->number = $number;
        $this->propertyId = $propertyId;
        $this->terms = $terms;
        $this->events = new ArrayCollection();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function number(): int
    {
        return $this->number;
    }

    public function propertyId(): string
    {
        return $this->propertyId;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function lender(): string
    {
        return $this->lender;
    }

    public function describe(string $label, string $lender): void
    {
        $this->label = Trimmed::orNull($label) ?? '';
        $this->lender = Trimmed::orNull($lender) ?? '';
    }

    public function terms(): LoanTerms
    {
        return $this->terms;
    }

    public function agreeOn(LoanTerms $terms): void
    {
        $this->terms = $terms;
    }

    public function reference(): string
    {
        return $this->reference;
    }

    /** Aus welchem Beschluss es stammt — gesetzt von der Freigabe des Budgetplans. */
    public function comesFrom(string $reference): void
    {
        $this->reference = $reference;
    }

    public function note(): string
    {
        return $this->note;
    }

    public function noteThat(string $note): void
    {
        $this->note = trim($note);
    }

    /** @return list<LoanEvent> */
    public function events(): array
    {
        return array_values($this->events->toArray());
    }

    public function record(LoanEvent $event): void
    {
        $this->events->add($event);
    }

    public function forget(LoanEvent $event): void
    {
        $this->events->removeElement($event);
    }

    /** Die Summe, die aufgenommen wurde. */
    public function amount(): Money
    {
        return $this->terms->amount();
    }
}
