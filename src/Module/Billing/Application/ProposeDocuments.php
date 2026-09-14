<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\Letting;
use App\Module\Billing\Domain\ProposedAdvance;
use App\Module\Billing\Domain\ProposedDocument;
use App\Module\Billing\Domain\ProposedLine;
use App\Module\Billing\Domain\Statement;
use App\Module\Billing\Domain\StatementKind;
use App\Module\Billing\Domain\Taxation;
use App\Module\Finance\Contract\CostRecord;
use App\Module\Finance\Contract\PaymentRecord;
use App\Module\Property\Contract\PropertyDirectory;
use App\Module\Property\Contract\UnitBrief;
use App\Module\Tenancy\Contract\TenancySpan;
use DateTimeImmutable;

/**
 * Aus verteilten Betraegen werden Schreiben.
 *
 * Zwei Fragen, zwei Klassen: wie viel faellt auf eine Einheit, rechnet
 * {@see ShareOutCosts}; wer dafuer Post bekommt, steht hier.
 */
final readonly class ProposeDocuments
{
    public function __construct(
        private PropertyDirectory $properties,
        private Recipients $recipients,
    ) {
    }

    /**
     * @param list<UnitBrief>                            $units
     * @param array<string, list<TenancySpan>>           $spans
     * @param list<CostRecord>                           $costs
     * @param array<string, array<string, ProposedLine>> $amounts
     * @param list<PaymentRecord>                        $payments
     *
     * @return list<ProposedDocument>
     */
    public function of(
        Statement $statement,
        array $units,
        array $spans,
        array $costs,
        array $amounts,
        array $payments,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array {
        $documents = [];
        $isWeg = $this->isWeg($statement);

        foreach ($this->recipients->of($units, $spans, $statement->kinds(), $isWeg, $from, $to) as $whom) {
            $documents[] = self::documentFor($whom, $costs, $amounts, $payments, $from, $to);
        }

        return $documents;
    }

    /**
     * @param array{kind: StatementKind, unit: UnitBrief, from: DateTimeImmutable, to: DateTimeImmutable, label: string, address: string, tenancy: ?TenancySpan} $whom
     * @param list<CostRecord>                                                                                                                                   $costs
     * @param array<string, array<string, ProposedLine>>                                                                                                         $amounts
     * @param list<PaymentRecord>                                                                                                                                $payments
     */
    private static function documentFor(
        array $whom,
        array $costs,
        array $amounts,
        array $payments,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): ProposedDocument {
        return new ProposedDocument(
            kind: $whom['kind'],
            unitId: $whom['unit']->id,
            unitNumber: $whom['unit']->number,
            unitLabel: $whom['unit']->label,
            from: $whom['from'],
            to: $whom['to'],
            recipientLabel: $whom['label'],
            recipientAddress: $whom['address'],
            lines: LinesForRecipient::of(
                $whom['kind'],
                $costs,
                $amounts[$whom['unit']->id] ?? [],
                $whom['from'],
                $whom['to'],
                $from,
                $to,
            ),
            advances: self::advancesFor($whom['kind'], $whom['unit']->id, $whom['from'], $whom['to'], $payments),
            letting: self::lettingOf($whom['tenancy']),
        );
    }

    /**
     * Umsatzsteuer nur, wo das Mietverhaeltnis optiert hat.
     *
     * Die Hausgeldabrechnung hat keines und ist steuerfrei (§ 4 Nr. 13 UStG).
     */
    private static function lettingOf(?TenancySpan $tenancy): Letting
    {
        if (null === $tenancy) {
            return Letting::none();
        }

        return new Letting(
            $tenancy->tenancyId,
            $tenancy->number,
            $tenancy->vatCharged ? Taxation::at($tenancy->vatRateBps) : Taxation::exempt(),
        );
    }

    private function isWeg(Statement $statement): bool
    {
        $property = $this->properties->byIds([$statement->propertyId()])[$statement->propertyId()] ?? null;

        return null !== $property && $property->managesWeg;
    }

    /**
     * @param list<PaymentRecord> $payments
     *
     * @return list<ProposedAdvance>
     */
    private static function advancesFor(
        StatementKind $kind,
        string $unitId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        array $payments,
    ): array {
        $found = [];

        foreach ($payments as $payment) {
            if (self::belongs($payment, $kind, $unitId, $from, $to)) {
                $found[] = new ProposedAdvance(
                    $payment->paymentId,
                    $payment->dueOn,
                    $payment->expected,
                    $payment->received,
                    $payment->kind,
                );
            }
        }

        return $found;
    }

    /**
     * Gehoert diese Zahlung in dieses Schreiben?
     *
     * Entschieden wird es daran, **wer sie schuldet**, und nicht an einem
     * Namen: die Hausgeldabrechnung geht an den Eigentuemer und traegt, was
     * er schuldet — Hausgeld und Sonderumlage. Die Nebenkostenabrechnung geht
     * an den Mieter und traegt seine Vorauszahlung.
     *
     * Vorher wurde die Zahlungsart mit der Abrechnungsart verglichen. Das ging
     * gut, solange es zwei Arten gab, die zufaellig gleich hiessen — eine
     * Sonderumlage passte auf keine von beiden und verschwand: angeboten,
     * angehakt, und auf keinem Blatt.
     */
    private static function belongs(
        PaymentRecord $payment,
        StatementKind $kind,
        string $unitId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): bool {
        return $payment->unitId === $unitId
            && $payment->owedByTheOwner === (StatementKind::HouseMoney === $kind)
            && $payment->dueOn >= $from
            && $payment->dueOn <= $to;
    }
}
