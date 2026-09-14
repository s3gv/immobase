<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\RentInvoiceRepository;
use App\Module\Tenancy\Contract\TenancyBrief;
use App\Module\Tenancy\Contract\TenancyDirectory;
use DateTimeImmutable;

/**
 * Mietstufen, zu denen keine Dauermietrechnung ausgestellt ist.
 *
 * Eine Dauermietrechnung gilt, **bis sich ein Bestandteil aendert** — und
 * dann stellt der Vermieter dem Mieter *vorher* eine neue aus. Jede Stufe
 * der Mietstaffel ist so eine Aenderung. Wer sie uebersieht, hat eine
 * Rechnung im Umlauf, die einen falschen Betrag nennt.
 *
 * **Gemeldet wird nur, wo schon einmal eine ausgestellt wurde.** Eine
 * Verwaltung mit zweihundert Wohnungen, die nie Dauermietrechnungen
 * schreibt, bekaeme sonst zweihundert Hinweise und gewoehnte sich alle ab —
 * auch die zwei echten. Wer einmal eine geschrieben hat, will bei der
 * naechsten Stufe wieder eine.
 *
 * Kuenftige Stufen zaehlen mit, und zwar mit Absicht: die Rechnung muss
 * **vor** der Aenderung hinaus. Ein Hinweis, der erst am Stichtag kaeme,
 * kaeme zu spaet.
 */
final readonly class StepsWithoutAnInvoice
{
    public function __construct(
        private RentInvoiceRepository $invoices,
        private TenancyDirectory $tenancies,
    ) {
    }

    /**
     * @return list<array{tenancy: TenancyBrief, from: DateTimeImmutable}>
     */
    public function all(): array
    {
        $known = array_flip($this->invoices->tenanciesWithAnInvoice());
        $pending = [];

        foreach ($this->tenancies->lettable() as $tenancy) {
            if (!isset($known[$tenancy->tenancyId])) {
                continue;
            }

            $from = $this->uncoveredStepOf($tenancy);

            if (null !== $from) {
                $pending[] = ['tenancy' => $tenancy, 'from' => $from];
            }
        }

        return $pending;
    }

    /**
     * Die aelteste Stufe, fuer die keine Rechnung ausgestellt ist.
     *
     * **Gefragt wird nicht, ob eine Fassung den Tag abdeckt.** Die letzte
     * Fassung ist offen und deckt damit jeden kuenftigen Tag ab — sie nennt
     * aber die Betraege der Stufe, aus der sie gerechnet wurde. Genau das ist
     * die gefaehrliche Lage: eine Rechnung im Umlauf, die zu wenig fordert.
     *
     * Eine Stufe ist darum gedeckt, wenn eine ausgestellte Fassung **in ihr
     * beginnt** — zwischen ihrem ersten Tag und dem Beginn der naechsten.
     * Nur so eine Fassung traegt ihre Betraege.
     */
    private function uncoveredStepOf(TenancyBrief $tenancy): ?DateTimeImmutable
    {
        $issued = array_values(array_filter(
            $this->invoices->forTenancy($tenancy->tenancyId),
            static fn ($invoice): bool => !$invoice->release()->isDraft(),
        ));

        foreach ($tenancy->steps as $at => $step) {
            $until = $tenancy->steps[$at + 1]->from ?? null;

            foreach ($issued as $invoice) {
                $from = $invoice->validity()->from();

                if ($from >= $step->from && (null === $until || $from < $until)) {
                    continue 2;
                }
            }

            return $step->from;
        }

        return null;
    }
}
