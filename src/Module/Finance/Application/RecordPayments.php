<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Application;

use App\Module\Finance\Contract\StatementSources;
use App\Module\Finance\Domain\AdvancePayment;
use App\Module\Finance\Domain\AdvancePaymentRepository;
use App\Module\Finance\Domain\Due;
use App\Module\Property\Contract\PropertyDirectory;
use App\Module\Property\Contract\UnitDirectory;
use App\Shared\Money\Money;
use DateTimeImmutable;

/**
 * Die Zahlungen eines Wirtschaftsjahres — angelegt, nachgezogen, umgeschaltet.
 *
 * Die Eintraege entstehen beim Ansehen und nicht im Voraus: welche
 * Faelligkeiten ein Jahr hat, haengt an der Staffel, und die aendert sich.
 * Sie fuer alle Jahre vorzulegen hiesse, sie bei jeder Aenderung nachzuziehen.
 *
 * Was schon dasteht, behaelt seinen Zustand. Nur der Sollbetrag zieht nach,
 * wenn die Staffel sich geaendert hat — er ist eine Ableitung und keine
 * Eingabe.
 */
final readonly class RecordPayments
{
    public function __construct(
        private AdvancePaymentRepository $payments,
        private DueAdvances $due,
        private UnitDirectory $units,
        private PropertyDirectory $properties,
        private StatementSources $statements,
    ) {
    }

    /**
     * Die Zahlungen eines Jahres — nachgezogen und zurueckgegeben.
     *
     * Zurueck kommen **alle**, auch die Sonderumlagen: sie stehen auf
     * derselben Seite. Nachgezogen wird nur, was aus einer Staffel kommt.
     *
     * @return list<AdvancePayment> zeitlich sortiert
     */
    public function forYear(string $unitId, int $fiscalYear): array
    {
        $period = $this->periodOf($unitId, $fiscalYear);

        if (null === $period) {
            return [];
        }

        $due = $this->due->forUnit($unitId, $period['from'], $period['to']);
        $known = $this->known($unitId, $fiscalYear);

        $this->payments->saveAll(self::grown($unitId, $fiscalYear, $due, $known));
        $this->payments->removeAll($this->unused(self::gone($due, $known)));

        return $this->payments->forYear([$unitId], $fiscalYear);
    }

    public function settle(AdvancePayment $payment): void
    {
        $payment->settle();
        $this->payments->saveAll([$payment]);
    }

    public function miss(AdvancePayment $payment, ?Money $part): void
    {
        $payment->missed($part);
        $this->payments->saveAll([$payment]);
    }

    /**
     * Der Zeitraum des Wirtschaftsjahres dieser Einheit.
     *
     * @return array{from: DateTimeImmutable, to: DateTimeImmutable}|null
     */
    private function periodOf(string $unitId, int $fiscalYear): ?array
    {
        $unit = $this->units->byIds([$unitId])[$unitId] ?? null;
        $property = null === $unit ? null : ($this->properties->byIds([$unit->propertyId])[$unit->propertyId] ?? null);

        if (null === $property) {
            return null;
        }

        $from = new DateTimeImmutable(\sprintf(
            '%04d-%02d-%02d',
            $fiscalYear,
            $property->fiscalYearMonth,
            $property->fiscalYearDay,
        ));

        return ['from' => $from, 'to' => $from->modify('+1 year')->modify('-1 day')];
    }

    /**
     * Was in einer Abrechnung steckt, bleibt stehen.
     *
     * Gefragt wird vorher und nicht am Fremdschluessel: dessen Absage liest
     * niemand, und ein gescheiterter Flush naehme den ganzen Abgleich mit.
     *
     * @param list<AdvancePayment> $payments
     *
     * @return list<AdvancePayment>
     */
    private function unused(array $payments): array
    {
        if ([] === $payments) {
            return [];
        }

        $held = $this->statements->usedByAStatement(array_map(
            static fn (AdvancePayment $payment): string => $payment->id(),
            $payments,
        ));

        return array_values(array_filter(
            $payments,
            static fn (AdvancePayment $payment): bool => !isset($held[$payment->id()]),
        ));
    }

    /**
     * @return array<string, AdvancePayment>
     */
    private function known(string $unitId, int $fiscalYear): array
    {
        $known = [];

        foreach ($this->payments->forYear([$unitId], $fiscalYear) as $payment) {
            // Was nicht aus einer Staffel kommt, wird hier nicht nachgezogen:
            // eine Sonderumlage ist beschlossen, und ein Blick auf die
            // Zahlungsseite nimmt einen Beschluss nicht zurueck.
            if ($payment->kind()->comesFromASchedule()) {
                $known[(new Due($payment->kind(), $payment->dueOn(), $payment->expected()))->key()] = $payment;
            }
        }

        return $known;
    }

    /**
     * Neue Faelligkeiten anlegen, bekannte im Sollbetrag nachziehen.
     *
     * @param array<string, Due>            $due
     * @param array<string, AdvancePayment> $known
     *
     * @return list<AdvancePayment>
     */
    private static function grown(string $unitId, int $fiscalYear, array $due, array $known): array
    {
        $touched = [];

        foreach ($due as $key => $owed) {
            $payment = $known[$key] ?? null;

            if (null === $payment) {
                $touched[] = new AdvancePayment(
                    $unitId,
                    $owed->kind,
                    $fiscalYear,
                    $owed->dueOn,
                    $owed->expected,
                );

                continue;
            }

            if (!$payment->expected()->equals($owed->expected)) {
                $payment->expect($owed->expected);
                $touched[] = $payment;
            }
        }

        return $touched;
    }

    /**
     * Was nicht mehr faellig ist.
     *
     * @param array<string, Due>            $due
     * @param array<string, AdvancePayment> $known
     *
     * @return list<AdvancePayment>
     */
    private static function gone(array $due, array $known): array
    {
        return array_values(array_filter(
            $known,
            static fn (string $key): bool => !isset($due[$key]),
            \ARRAY_FILTER_USE_KEY,
        ));
    }
}
