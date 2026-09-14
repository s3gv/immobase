<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Contract;

use DateTimeImmutable;

/**
 * Ein Mietverhaeltnis, so viel wie ein fremdes Modul davon sehen darf.
 *
 * Gebaut fuer die Dauermietrechnung: sie braucht den Vertrag als Ganzes —
 * wer mietet, was, ab wann, zu welchem Preis und ob mit Umsatzsteuer. Die
 * Staffel kommt mit, weil aus ihr hervorgeht, **wann eine neue Rechnung
 * faellig wird**: jede Stufe aendert einen Bestandteil.
 *
 * Entwuerfe stehen nicht drin. Ein Mietverhaeltnis, das noch nicht in Kraft
 * ist, hat nichts, worueber sich eine Rechnung stellen liesse.
 */
final readonly class TenancyBrief
{
    /**
     * @param list<string>     $tenantPartyIds die Mieter, in ihrer Reihenfolge
     * @param list<RentPeriod> $steps          die Mietstaffel, die aelteste zuerst
     */
    public function __construct(
        public string $tenancyId,
        public int $number,
        public string $unitId,
        public int $unitNumber,
        public string $unitLabel,
        public string $propertyId,
        public int $propertyNumber,
        public string $propertyName,
        /** Die Anschrift des Objekts — auf einer Rechnung muss der Mietgegenstand auffindbar sein. */
        public string $address,
        public ?DateTimeImmutable $from,
        public ?DateTimeImmutable $to,
        public array $tenantPartyIds,
        public bool $vatCharged,
        public int $vatRateBps,
        public array $steps = [],
        /** `transfer` oder `direct_debit` */
        public string $paymentMethod = 'transfer',
        /** `third_working_day`, `month_start` oder `month_end` */
        public string $paymentDue = 'third_working_day',
        /** Was eine E-Rechnung ueber den Mieter wissen muss — alles vom Mieter erfragt. */
        public string $buyerReference = '',
        public string $buyerEAddress = '',
        public string $sepaMandate = '',
        public string $debtorIban = '',
    ) {
    }

    /** Die Stufe, die an diesem Tag gilt — keine, wenn der Vertrag dann noch nicht lief. */
    public function stepOn(DateTimeImmutable $day): ?RentPeriod
    {
        $found = null;

        foreach ($this->steps as $step) {
            if ($step->from <= $day) {
                $found = $step;
            }
        }

        return $found;
    }

    /** Laeuft der Vertrag an diesem Tag? Ohne Ende laeuft er weiter. */
    public function runsOn(DateTimeImmutable $day): bool
    {
        return null !== $this->from && $this->from <= $day && (null === $this->to || $this->to >= $day);
    }

    public function oneLine(): string
    {
        return $this->number.' · '.$this->unitLabel;
    }
}
