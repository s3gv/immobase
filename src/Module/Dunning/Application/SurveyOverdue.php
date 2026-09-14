<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Application;

use App\Module\Dunning\Domain\ClaimRepository;
use App\Module\Dunning\Domain\Creditor;
use App\Module\Dunning\Domain\CreditorIdentity;
use App\Module\Finance\Contract\AdvanceDirectory;
use App\Module\Finance\Contract\PaymentRecord;
use App\Module\Party\Contract\PartyBrief;
use App\Module\Party\Contract\PartyDirectory;
use App\Module\Property\Contract\OwnerShare;
use App\Module\Property\Contract\OwnershipSpan;
use App\Module\Property\Contract\UnitBrief;
use App\Module\Property\Contract\UnitDirectory;
use App\Module\Property\Contract\UnitOwnership;
use App\Module\Tenancy\Contract\TenancyDirectory;
use DateTimeImmutable;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Was ueberfaellig ist und noch niemanden beschaeftigt hat.
 *
 * Die Tafel auf der Uebersicht — abgeleitet aus den Finanzen und nicht
 * gespeichert. Eine Tabelle, die jede verspaetete Zahlung mitschreibt, waere
 * eine zweite Wahrheit ueber dasselbe, und die erste Zahlung, die jemand
 * nachtraeglich korrigiert, liesse beide auseinanderlaufen.
 *
 * Wer der Schuldner ist, haengt an der Art der Zahlung: Hausgeld und
 * Sonderumlage schuldet der Eigentuemer, die Nebenkostenvorauszahlung der
 * Mieter. Dieselbe Unterscheidung bestimmt den Glaeubiger.
 */
final readonly class SurveyOverdue
{
    public function __construct(
        private AdvanceDirectory $advances,
        private ClaimRepository $claims,
        private UnitDirectory $units,
        private UnitOwnership $ownership,
        private TenancyDirectory $tenancies,
        private PartyDirectory $parties,
        private TheCreditor $creditor,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return list<OverdueItem> die aelteste Faelligkeit zuerst
     */
    public function on(DateTimeImmutable $day): array
    {
        $payments = $this->advances->overdueOn($day);
        $known = array_flip($this->claims->advancesWithAClaim());
        $open = array_values(array_filter(
            $payments,
            static fn (PaymentRecord $payment): bool => !isset($known[$payment->paymentId]),
        ));

        if ([] === $open) {
            return [];
        }

        return $this->itemsOf($open, $day);
    }

    /**
     * @param list<PaymentRecord> $payments
     *
     * @return list<OverdueItem>
     */
    private function itemsOf(array $payments, DateTimeImmutable $day): array
    {
        $unitIds = array_values(array_unique(array_map(
            static fn (PaymentRecord $payment): string => $payment->unitId,
            $payments,
        )));
        $units = $this->units->byIds($unitIds);
        $debtors = $this->debtorsOf($payments, $unitIds, $day);
        $parties = $this->parties->byIds(array_values(array_unique(array_values($debtors))));
        $creditors = $this->creditorsOf($payments, $units);

        $items = [];

        foreach ($payments as $payment) {
            $unit = $units[$payment->unitId] ?? null;
            $debtor = $parties[$debtors[self::keyOf($payment)] ?? ''] ?? null;
            $creditor = $creditors[self::keyOf($payment)] ?? null;

            if (null === $unit || null === $debtor || null === $creditor) {
                continue;
            }

            $items[] = $this->item($payment, $unit->label, $creditor, $debtor, $day);
        }

        return $items;
    }

    /**
     * Wer bei jeder dieser Zahlungen fordert — in einer Abfrage.
     *
     * Hausgeld und Sonderumlage schuldet der Eigentuemer der Gemeinschaft,
     * die Nebenkosten schuldet der Mieter seinem Vermieter. Wer der Vermieter
     * ist, haengt an der Einheit und am **Tag der Faelligkeit**: wer
     * inzwischen verkauft hat, war damals der Glaeubiger.
     *
     * @param list<PaymentRecord>      $payments
     * @param array<string, UnitBrief> $units
     *
     * @return array<string, CreditorIdentity> Zahlungsschluessel auf Glaeubiger
     */
    private function creditorsOf(array $payments, array $units): array
    {
        $found = $this->creditor->each(array_map(
            static fn (PaymentRecord $payment): array => [
                'role' => $payment->owedByTheOwner ? Creditor::Community : Creditor::Owner,
                'propertyId' => $units[$payment->unitId]->propertyId ?? '',
                'unitId' => $payment->unitId,
                'on' => $payment->dueOn,
            ],
            $payments,
        ));

        return array_combine(array_map(self::keyOf(...), $payments), $found);
    }

    private function item(
        PaymentRecord $payment,
        string $unitLabel,
        CreditorIdentity $creditor,
        PartyBrief $debtor,
        DateTimeImmutable $day,
    ): OverdueItem {
        $from = $payment->dueOn->modify('+1 day');

        return new OverdueItem(
            paymentId: $payment->paymentId,
            unitId: $payment->unitId,
            propertyId: $creditor->propertyId,
            unitLabel: $unitLabel,
            subject: $this->subjectOf($payment),
            creditor: $creditor,
            debtorPartyId: $debtor->id,
            debtorName: $debtor->displayName,
            debtorIsACompany: $debtor->isACompany(),
            dueOn: $payment->dueOn,
            open: $payment->expected->minus($payment->received),
            daysOverdue: $from > $day ? 0 : (int) $from->diff($day)->days,
        );
    }

    /**
     * „Hausgeld 03/2026" — woran der Empfaenger die Forderung wiedererkennt.
     *
     * Der Monat und nicht das Datum: eine Vorauszahlung ist die des Monats,
     * und „faellig am 03.03." saehe nach einer Rechnung aus.
     */
    private function subjectOf(PaymentRecord $payment): string
    {
        return $this->translator->trans('finance.payment.kind.'.$payment->kind).' '.$payment->dueOn->format('m/Y');
    }

    /**
     * Wer welche Zahlung schuldet — der Eigentuemer oder der Mieter.
     *
     * Beide auf einmal nachgeschlagen: bei zweihundert offenen Zahlungen
     * waeren es sonst vierhundert Abfragen.
     *
     * @param list<PaymentRecord> $payments
     * @param list<string>        $unitIds
     *
     * @return array<string, string> Zahlungsschluessel auf Kennung der Partei
     */
    private function debtorsOf(array $payments, array $unitIds, DateTimeImmutable $day): array
    {
        $owners = $this->ownership->inPeriod($unitIds, $day, $day);
        $tenancies = [];

        foreach ($this->tenancies->lettable() as $tenancy) {
            if ($tenancy->runsOn($day)) {
                $tenancies[$tenancy->unitId] = $tenancy->tenantPartyIds[0] ?? null;
            }
        }

        $found = [];

        foreach ($payments as $payment) {
            $found[self::keyOf($payment)] = $payment->owedByTheOwner
                ? self::firstOwner($owners[$payment->unitId] ?? [])
                : ($tenancies[$payment->unitId] ?? null);
        }

        return array_filter($found, static fn (?string $partyId): bool => null !== $partyId);
    }

    /** @param list<OwnershipSpan> $spans */
    private static function firstOwner(array $spans): ?string
    {
        $span = $spans[0] ?? null;

        if (null === $span) {
            return null;
        }

        return array_map(static fn (OwnerShare $share): string => $share->partyId, $span->owners)[0] ?? null;
    }

    private static function keyOf(PaymentRecord $payment): string
    {
        return $payment->paymentId;
    }
}
