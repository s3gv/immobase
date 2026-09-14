<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\Budget;
use App\Module\Billing\Domain\BudgetIsDecided;
use App\Module\Billing\Domain\BudgetIsIncomplete;
use App\Module\Billing\Domain\BudgetRepository;
use DateTimeImmutable;

/**
 * Die Beschlussvorlage geht heraus — und wird dabei eingefroren.
 *
 * Dasselbe Versprechen wie beim Wirtschaftsplan: „herausgegeben am 30.
 * Oktober" ist eine Auskunft darueber, *was* an diesem Tag vorlag. Rechnete
 * das Blatt beim Herunterladen neu, koennte jede spaetere Aenderung es
 * veraendern.
 *
 * **Was die Vorlage noch nicht weiss, ist der Verteilerkreis.** Bei einer
 * baulichen Veraenderung entscheidet die Versammlung nicht nur, ob gebaut
 * wird, sondern auch, wer zahlt (§ 21 WEG). Die Vorlage zeigt die Annahme;
 * der Beschluss rechnet neu. Das ist kein Widerspruch zum Einfrieren, sondern
 * der Grund, warum die Vorlage „Vorlage" heisst.
 */
final readonly class ProposeBudget
{
    public function __construct(
        private BudgetRepository $budgets,
        private ComposeBudget $compose,
    ) {
    }

    /**
     * @throws BudgetIsDecided
     * @throws BudgetIsIncomplete
     */
    public function propose(Budget $budget, DateTimeImmutable $on): void
    {
        if (!$budget->stage()->isOpen()) {
            throw BudgetIsDecided::already();
        }

        $proposal = $this->compose->of($budget);

        if ([] !== $proposal->missing) {
            throw BudgetIsIncomplete::theFundingDoesNotCover();
        }

        if ([] === $proposal->shares) {
            throw BudgetIsIncomplete::thereIsNoOneToSendTo();
        }

        FreezeBudget::of($budget, $proposal);
        $budget->proposeOn($on);
        $this->budgets->save($budget);
    }
}
