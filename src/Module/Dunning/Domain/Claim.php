<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Domain;

use App\Shared\Identity\Uuid;
use App\Shared\Money\Money;
use App\Shared\Text\Trimmed;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ein Rueckstand, der verfolgt wird.
 *
 * **Die verfolgte Einheit ist die Forderung, nicht der Schuldner.** Das folgt
 * aus der Bedienung: eine Zahlung wird als erhalten gekennzeichnet, und
 * *dieser* Vorgang endet — die anderen laufen weiter. Ein Vorgang je
 * Schuldner bliebe offen, weil noch eine andere Rate fehlt, und muesste
 * trotzdem jede Forderung einzeln verzinsen, weil jede ihren eigenen
 * Verzugsbeginn hat.
 *
 * **Sie entsteht erst, wenn wirklich gemahnt wird.** Vorher steht alles schon
 * in den Finanzen, und eine Tabelle, die jede verspaetete Zahlung
 * mitschreibt, waere eine zweite Wahrheit darueber.
 *
 * Was sie sich merkt und die Finanzen nicht wissen, ist der Erledigungstag:
 * eine Vorauszahlung kennt bewusst kein Zahlungsdatum, und ohne Tag laesst
 * sich kein Zinsanspruch belegen.
 *
 * Ein Betragsfeld hat sie nicht — was offen ist, steht in {@see ClaimStep}.
 */
#[ORM\Entity]
#[ORM\Table(name: 'dunning_claim')]
#[ORM\UniqueConstraint(name: 'dunning_claim_origin', columns: ['origin', 'origin_id'])]
#[ORM\Index(name: 'dunning_claim_debtor', columns: ['debtor_party_id'])]
class Claim
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Embedded(class: Debtor::class, columnPrefix: false)]
    private Debtor $debtor;

    #[ORM\Embedded(class: Source::class, columnPrefix: false)]
    private Source $source;

    #[ORM\Embedded(class: Arrears::class, columnPrefix: false)]
    private Arrears $arrears;

    #[ORM\Column(type: Types::STRING, length: 200)]
    private string $subject = '';

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    /** @var Collection<int, ClaimStep> */
    #[ORM\OneToMany(targetEntity: ClaimStep::class, mappedBy: 'claim', cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['startsOn' => 'ASC'])]
    private Collection $steps;

    public function __construct(Debtor $debtor, Source $source, Arrears $arrears)
    {
        $this->id = Uuid::v4();
        $this->debtor = $debtor;
        $this->source = $source;
        $this->arrears = $arrears;
        $this->createdAt = new DateTimeImmutable();
        $this->steps = new ArrayCollection();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function debtor(): Debtor
    {
        return $this->debtor;
    }

    public function source(): Source
    {
        return $this->source;
    }

    public function arrears(): Arrears
    {
        return $this->arrears;
    }

    public function subject(): string
    {
        return $this->subject;
    }

    public function describe(string $subject): void
    {
        $this->subject = Trimmed::orNull($subject) ?? '';
    }

    public function tradeAs(bool $commercial): void
    {
        $this->debtor = $this->debtor->tradingAs($commercial);
    }

    public function claimTheFlatFee(): void
    {
        $this->debtor = $this->debtor->withTheFlatFee();
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return list<ClaimStep> */
    public function steps(): array
    {
        return array_values($this->steps->toArray());
    }

    /** @internal Von {@see ClaimStep} beim Anlegen aufgerufen. */
    public function add(ClaimStep $step): void
    {
        if (!$this->steps->contains($step)) {
            $this->steps->add($step);
        }
    }

    /** Was zuletzt offen war — die Zahl, die in jeder Liste steht. */
    public function open(): Money
    {
        $steps = $this->steps();

        return ($steps[\count($steps) - 1] ?? null)?->open() ?? Money::zero();
    }

    /**
     * Der offene Betrag hat sich geaendert.
     *
     * Am selben Tag wird die Stufe geaendert statt eine zweite danebengelegt:
     * zwei Stufen mit demselben Beginn waeren ein Zeitraum von null Tagen,
     * und die Staffel zeigte eine Zeile, die nichts verzinst.
     */
    public function nowOpen(Money $open, DateTimeImmutable $on): void
    {
        $steps = $this->steps();
        $last = $steps[\count($steps) - 1] ?? null;

        if (null !== $last && $last->open()->equals($open)) {
            return;
        }

        if (null !== $last && $last->startsOn() >= $on) {
            $last->change($open);

            return;
        }

        new ClaimStep($this, $on, $open);
    }

    /**
     * Erledigt an diesem Tag.
     *
     * @throws ClaimIsSettled
     */
    public function settle(DateTimeImmutable $on): void
    {
        if (!$this->arrears->isOpen()) {
            throw ClaimIsSettled::already();
        }

        $begins = $this->arrears->beginsOn();
        $this->nowOpen(Money::zero(), $on < $begins ? $begins : $on);
        $this->arrears = $this->arrears->endingOn($on);
    }
}
