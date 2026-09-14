<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Pdf;

use App\Module\Billing\Domain\Distribution;
use App\Shared\Pdf\Amounts;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Ein Verteilerschluessel in Worten.
 *
 * „nach Wohnflaeche: 78,40 von 142,60" — und die Tage, wenn unterjaehrig
 * geteilt wurde. Ohne diesen Satz stuende auf der Abrechnung eine Zahl, deren
 * Zustandekommen niemand nachvollziehen kann, und genau daran entzuenden sich
 * die Rueckfragen.
 *
 * Steht hier und nicht bei {@see Amounts}: eine Verteilung ist ein Begriff der
 * Abrechnung, und Shared kennt kein Fachmodul.
 */
final readonly class Shares
{
    public function __construct(
        private Amounts $amounts,
        private TranslatorInterface $translator,
    ) {
    }

    public function of(Distribution $distribution): string
    {
        $text = \sprintf(
            '%s: %s %s %s',
            $this->translator->trans($distribution->explanation()),
            $this->amounts->number($distribution->shareOf()),
            $this->translator->trans('billing.pdf.of'),
            $this->amounts->number($distribution->shareTotal()),
        );

        if (!$distribution->isSplitByDay()) {
            return $text;
        }

        return $text.', '.$this->translator->trans('billing.line.days', [
            '%of%' => $distribution->daysOf(),
            '%total%' => $distribution->daysTotal(),
        ]);
    }
}
