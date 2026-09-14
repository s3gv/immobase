<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\AssetReportFilter;
use App\Module\Billing\Domain\AssetReportRepository;
use App\Module\Billing\Domain\BillingPermissions;
use App\Module\Billing\Domain\BudgetFilter;
use App\Module\Billing\Domain\BudgetRepository;
use App\Module\Billing\Domain\PlanFilter;
use App\Module\Billing\Domain\PlanRepository;
use App\Module\Billing\Domain\RentInvoiceFilter;
use App\Module\Billing\Domain\RentInvoiceRepository;
use App\Module\Billing\Domain\StatementFilter;
use App\Module\Billing\Domain\StatementRepository;
use App\Module\Billing\Domain\StatementStatus;
use App\Module\Billing\UserInterface\Twig\CorrectionBadge;
use App\Shared\Todo\ContributesTodos;
use App\Shared\Todo\Todo;
use App\Shared\Todo\TodoKind;
use App\Shared\Todo\Urgency;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Was die Abrechnung auf die Uebersicht meldet.
 *
 * **Entwuerfe, je Art eine Zeile.** Ein Entwurf ist angefangene Arbeit; er
 * fuehrt dorthin, wo sie liegen geblieben ist. Zusammengezaehlt waere es eine
 * Zahl, mit der niemand etwas anfangen kann: „7 Entwuerfe" sagt nicht, ob
 * sieben Abrechnungen offen sind oder sieben Rechnungen.
 *
 * **Die faellige Korrektur ist eine Meldung.** Niemand hat sie angelegt —
 * jemand hat anderswo eine Zahl geaendert, und eine freigegebene Abrechnung
 * stimmt seitdem nicht mehr. Ihre Zahl kommt aus der Sitzung und nicht aus
 * einer Abfrage: sie neu zu bestimmen hiesse, jede freigegebene Abrechnung
 * nachzurechnen, und das ist zu viel fuer einen Seitenaufruf. Dieselbe Zahl
 * wie am Menuepunkt, aus derselben Quelle.
 */
#[AsTaggedItem(priority: 60)]
final readonly class BillingTodos implements ContributesTodos
{
    public function __construct(
        private StatementRepository $statements,
        private PlanRepository $plans,
        private BudgetRepository $budgets,
        private AssetReportRepository $reports,
        private RentInvoiceRepository $invoices,
        private CorrectionBadge $badge,
        private AuthorizationCheckerInterface $mayView,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function todos(): array
    {
        if (!$this->mayView->isGranted(BillingPermissions::VIEW)) {
            return [];
        }

        $todos = $this->corrections();

        foreach ($this->drafts() as $route => [$labelKey, $count, $parameter]) {
            if ($count > 0) {
                $todos[] = $this->draft($route, $labelKey, $count, $parameter);
            }
        }

        return $todos;
    }

    /**
     * @return list<Todo>
     */
    private function corrections(): array
    {
        $count = $this->badge->count();

        if (0 === $count) {
            return [];
        }

        $url = $this->urls->generate('app_billing_statement');

        return [new Todo(
            kind: TodoKind::Notice,
            urgency: Urgency::Warning,
            labelKey: 'todo.billing.corrections',
            params: ['%count%' => $count],
            count: $count,
            url: $url,
            actionKey: 'todo.action.correct',
            actionUrl: $url,
        )];
    }

    /**
     * Route, Schluessel, Anzahl — und der Name des Statusfelds in der
     * Adresszeile.
     *
     * Der unterscheidet sich: die vier Schreiben mit Wirtschaftsjahr filtern
     * ueber `zustand`, die Dauermietrechnung ueber `status`. Ein Knopf, der
     * den falschen Namen mitgibt, fuehrt in eine ungefilterte Liste — und
     * das faellt niemandem auf, weil dort trotzdem etwas steht.
     *
     * @return array<string, array{string, int, string}>
     */
    private function drafts(): array
    {
        return [
            'app_billing_statement' => ['todo.billing.statements', $this->statements->countMatching(StatementFilter::draftsOnly()), 'zustand'],
            'app_billing_plan' => ['todo.billing.plans', $this->plans->countMatching(PlanFilter::draftsOnly()), 'zustand'],
            'app_billing_budget' => ['todo.billing.budgets', $this->budgets->countMatching(BudgetFilter::draftsOnly()), 'zustand'],
            'app_billing_report' => ['todo.billing.reports', $this->reports->countMatching(AssetReportFilter::draftsOnly()), 'zustand'],
            'app_billing_invoice' => ['todo.billing.invoices', $this->invoices->countMatching(RentInvoiceFilter::draftsOnly()), 'status'],
        ];
    }

    private function draft(string $route, string $labelKey, int $count, string $parameter): Todo
    {
        $url = $this->urls->generate($route, [$parameter => StatementStatus::Draft->value]);

        return new Todo(
            kind: TodoKind::Draft,
            urgency: Urgency::Neutral,
            labelKey: $labelKey,
            params: ['%count%' => $count],
            count: $count,
            url: $url,
            actionKey: 'todo.action.continue',
            actionUrl: $url,
        );
    }
}
