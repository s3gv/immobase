<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Controller;

use App\Module\Billing\Application\ComposeStatement;
use App\Module\Billing\Application\DraftSelection;
use App\Module\Billing\Application\MeasuresWithoutCosts;
use App\Module\Billing\Application\StatementInvoicing;
use App\Module\Billing\Domain\Statement;
use App\Module\Billing\Domain\StatementRepository;
use App\Module\Property\Contract\PropertyDirectory;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Request;

/**
 * Was jeder Schritt anzuzeigen hat.
 *
 * Getrennt vom Controller, weil das Zusammentragen mehr Zeilen braucht als
 * das Entscheiden — und weil die Vorschau dieselbe Berechnung benutzt wie die
 * Freigabe.
 */
final readonly class StatementView
{
    /** So weit zurueck laesst sich ein Wirtschaftsjahr waehlen. */
    private const int YEARS_BACK = 5;

    public function __construct(
        private StatementFlowPage $page,
        private ComposeStatement $compose,
        private DraftSelection $selection,
        private StatementRepository $statements,
        private PropertyDirectory $properties,
        private BillingPage $trail,
        private MeasuresWithoutCosts $missing,
        private StatementInvoicing $invoicing,
    ) {
    }

    /**
     * Der erste Schritt, bevor es die Abrechnung gibt.
     *
     * @return array<string, mixed>
     */
    public function start(Request $request): array
    {
        return [
            ...$this->page->frame(null, StatementFlow::BASICS),
            'statement' => null,
            'properties' => $this->properties->all(),
            'years' => self::years(),
            'submitted' => $request->request->all(),
            'trail' => $this->trail->trail('billing.statement.new'),
        ];
    }

    /**
     * @param string $recipient Schluessel des angezeigten Schreibens; leer
     *                          heisst: das erste
     *
     * @return array<string, mixed>
     */
    public function of(Statement $statement, string $step, string $recipient = ''): array
    {
        $chosen = $this->selection->of($statement);
        $offered = $this->compose->offered($statement);
        $proposal = $this->compose->of($statement, $chosen['costs'], $chosen['payments']);

        return [
            ...$this->page->frame($statement, $step),
            'statement' => $statement,
            'properties' => $this->properties->all(),
            'years' => self::years(),
            'submitted' => [],
            'costs' => $offered['costs'],
            'payments' => $offered['payments'],
            'chosenCosts' => $chosen['costs'],
            'chosenPayments' => $chosen['payments'],
            // Sonderumlagen, denen im Jahr keine Rechnung gegenuebersteht —
            // ein Hinweis im Zahlungsschritt und keine Sperre.
            'withoutCosts' => $this->missing->among($offered['payments'], $statement->fiscalYear()),
            'proposal' => $proposal,
            // Was Schreiben mit Umsatzsteuer fuer ihre Rechnung fehlt — die
            // Freigabe haelt dann auf, also sagt es die Vorschau vorher.
            'invoiceGaps' => StatementFlow::PREVIEW === $step ? $this->invoicing->gapsIn($statement, $proposal) : [],
            'shown' => $proposal->documentFor($recipient) ?? ($proposal->documents[0] ?? null),
            'withoutPdf' => $this->statements->withoutPdf($statement->id()),
            'trail' => $this->trail->trail('billing.statement.heading'),
        ];
    }

    /** @return list<int> */
    private static function years(): array
    {
        $thisYear = (int) (new DateTimeImmutable('today'))->format('Y');

        return range($thisYear, $thisYear - self::YEARS_BACK);
    }
}
