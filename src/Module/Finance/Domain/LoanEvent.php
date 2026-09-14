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
 * Eine Sondertilgung oder ein neuer Zins.
 *
 * Was hier steht, ist ein **Vorgang** und kein Ergebnis: der Tilgungsplan
 * wird daraus jedes Mal neu gerechnet. Wer statt dessen die Restschuld
 * fortschriebe, haette eine Zahl, die jemand pflegen muss — und die neben
 * der Rechnung herliefe.
 */
#[ORM\Entity]
#[ORM\Table(name: 'finance_loan_event')]
#[ORM\Index(name: 'finance_loan_event_loan', columns: ['loan_id', 'occurred_on'])]
class LoanEvent
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Loan::class, inversedBy: 'events')]
    #[ORM\JoinColumn(name: 'loan_id', nullable: false, onDelete: 'CASCADE')]
    private Loan $loan;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: LoanEventKind::class)]
    private LoanEventKind $kind;

    #[ORM\Column(name: 'occurred_on', type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $occurredOn;

    /** Bei einer Sondertilgung der Betrag, sonst null. */
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $amount;

    /** Beim neuen Zins der Satz in Basispunkten, sonst null. */
    #[ORM\Column(name: 'rate_bps', type: Types::INTEGER, nullable: true)]
    private ?int $rateBps;

    #[ORM\Column(type: Types::TEXT)]
    private string $note = '';

    /**
     * @throws IncompleteLoanEvent
     */
    public function __construct(
        Loan $loan,
        LoanEventKind $kind,
        DateTimeImmutable $occurredOn,
        ?Money $amount,
        ?int $rateBps,
    ) {
        self::complete($kind, $amount, $rateBps);
        $this->id = Uuid::v4();
        $this->loan = $loan;
        $this->kind = $kind;
        $this->occurredOn = $occurredOn;
        $this->amount = $amount?->cents();
        $this->rateBps = $rateBps;
        $loan->record($this);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function kind(): LoanEventKind
    {
        return $this->kind;
    }

    public function occurredOn(): DateTimeImmutable
    {
        return $this->occurredOn;
    }

    public function amount(): ?Money
    {
        return null === $this->amount ? null : Money::fromCents($this->amount);
    }

    public function rateBps(): ?int
    {
        return $this->rateBps;
    }

    public function note(): string
    {
        return $this->note;
    }

    public function noteThat(string $note): void
    {
        $this->note = trim($note);
    }

    /**
     * Die Zahl, die den Vorgang ausmacht, muss dastehen.
     *
     * Ein leeres Feld ist keine Null: „kein Zins angegeben" und „null
     * Prozent" sehen im Formular gleich aus, und wer beides gleich
     * behandelt, rechnet den Plan klaglos falsch.
     *
     * @throws IncompleteLoanEvent
     */
    private static function complete(LoanEventKind $kind, ?Money $amount, ?int $rateBps): void
    {
        if ($kind->needsAnAmount() && (null === $amount || $amount->isZero() || $amount->isNegative())) {
            throw IncompleteLoanEvent::withoutAnAmount();
        }

        if (!$kind->needsAnAmount() && null === $rateBps) {
            throw IncompleteLoanEvent::withoutARate();
        }
    }
}
