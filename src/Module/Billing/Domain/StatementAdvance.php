<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use App\Shared\Identity\Uuid;
use App\Shared\Money\Money;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Eine Vorauszahlung auf einem Schreiben.
 *
 * Soll und Ist nebeneinander, Zeile fuer Zeile. Der Empfaenger soll seine
 * eigenen Ueberweisungen wiedererkennen — abgezogen wird, was ankam.
 */
#[ORM\Entity]
#[ORM\Table(name: 'billing_advance')]
class StatementAdvance
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: StatementDocument::class, inversedBy: 'paid')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private StatementDocument $document;

    #[ORM\Column(name: 'due_on', type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $dueOn;

    #[ORM\Column(type: Types::BIGINT)]
    private int $expected;

    #[ORM\Column(type: Types::BIGINT)]
    private int $received;

    /**
     * Wofuer sie war.
     *
     * Eingefroren wie alles auf dem Blatt: die Zahlung selbst darf spaeter
     * verschwinden — was am Tag der Freigabe darauf stand, bleibt.
     */
    #[ORM\Column(type: Types::STRING, length: 16)]
    private string $kind;

    public function __construct(
        StatementDocument $document,
        DateTimeImmutable $dueOn,
        Money $expected,
        Money $received,
        string $kind,
    ) {
        $this->id = Uuid::v4();
        $this->document = $document;
        $this->dueOn = $dueOn;
        $this->expected = $expected->cents();
        $this->received = $received->cents();
        $this->kind = $kind;
        $document->addPayment($this);
    }

    /** Der Schluessel des Namens, zum Beispiel `finance.payment.kind.special_levy`. */
    public function kindKey(): string
    {
        return 'finance.payment.kind.'.$this->kind;
    }

    public function dueOn(): DateTimeImmutable
    {
        return $this->dueOn;
    }

    public function expected(): Money
    {
        return Money::fromCents($this->expected);
    }

    public function received(): Money
    {
        return Money::fromCents($this->received);
    }

    public function isShort(): bool
    {
        return $this->received < $this->expected;
    }
}
