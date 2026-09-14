<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Domain;

use App\Shared\Identity\Uuid;
use App\Shared\Money\Money;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Eine Forderung, wie sie auf dem Schreiben steht — eingefroren.
 *
 * Betreff, Betrag, Faelligkeit, Verzugsbeginn und die Zinsen bis zum
 * Ausstellungstag. Sie stehen hier und werden nicht bei jedem Aufruf neu aus
 * der Forderung gelesen: das Blatt liegt beim Schuldner, die Zinsen laufen
 * weiter, und beim naechsten Aufruf muessen dieselben Zahlen dastehen.
 *
 * Die Kennung der Forderung bleibt dabei — nicht, um von ihr zu lesen,
 * sondern damit sich hinterher sagen laesst, worueber dieses Schreiben ging.
 */
#[ORM\Entity]
#[ORM\Table(name: 'dunning_notice_line')]
#[ORM\Index(name: 'dunning_line_notice', columns: ['notice_id'])]
class NoticeLine
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Notice::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'notice_id', nullable: false, onDelete: 'CASCADE')]
    private Notice $notice;

    #[ORM\Column(name: 'claim_id', type: Types::GUID)]
    private string $claimId;

    #[ORM\Column(type: Types::STRING, length: 200)]
    private string $subject;

    #[ORM\Column(type: Types::BIGINT)]
    private int $amount;

    #[ORM\Column(name: 'due_on', type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $dueOn;

    #[ORM\Column(name: 'default_from', type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $defaultFrom;

    #[ORM\Column(type: Types::BIGINT)]
    private int $interest;

    public function __construct(
        Notice $notice,
        string $claimId,
        string $subject,
        Money $amount,
        DateTimeImmutable $dueOn,
        DateTimeImmutable $defaultFrom,
        Money $interest,
    ) {
        $this->id = Uuid::v4();
        $this->notice = $notice;
        $this->claimId = $claimId;
        $this->subject = $subject;
        $this->amount = $amount->cents();
        $this->dueOn = $dueOn;
        $this->defaultFrom = $defaultFrom;
        $this->interest = $interest->cents();
        $notice->add($this);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function claimId(): string
    {
        return $this->claimId;
    }

    public function subject(): string
    {
        return $this->subject;
    }

    public function amount(): Money
    {
        return Money::fromCents($this->amount);
    }

    public function dueOn(): DateTimeImmutable
    {
        return $this->dueOn;
    }

    public function defaultFrom(): DateTimeImmutable
    {
        return $this->defaultFrom;
    }

    /**
     * Die Hauptforderungen zusammen — die Summe der gedruckten Zeilen.
     *
     * Hier und nicht beim Schreiben: was eine Zeile beitraegt, weiss die
     * Zeile. Und beide Summen rechnen ueber dieselbe Liste, damit nirgends
     * eine dritte Zaehlweise entsteht.
     *
     * @param list<self> $lines
     */
    public static function amountOf(array $lines): Money
    {
        return self::sumOf($lines, static fn (self $line): Money => $line->amount());
    }

    /** @param list<self> $lines */
    public static function interestOf(array $lines): Money
    {
        return self::sumOf($lines, static fn (self $line): Money => $line->interest());
    }

    public function interest(): Money
    {
        return Money::fromCents($this->interest);
    }

    /**
     * @param list<self>            $lines
     * @param callable(self): Money $of
     */
    private static function sumOf(array $lines, callable $of): Money
    {
        $sum = Money::zero();

        foreach ($lines as $line) {
            $sum = $sum->plus($of($line));
        }

        return $sum;
    }
}
