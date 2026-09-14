<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Controller;

use App\Module\Billing\Domain\Statement;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Was die Vorlage zum Zeichnen eines Schrittes braucht.
 *
 * Getrennt vom Controller, damit der bei den Aktionen bleibt: er entscheidet,
 * was passiert, nicht wie es heisst.
 */
final readonly class StatementFlowPage
{
    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * Der Rahmen eines Schrittes.
     *
     * `$editable` entscheidet, ob die Schritte Links sind. Eine freigegebene
     * Abrechnung wird im selben Rahmen angesehen wie sie entstanden ist —
     * aber zurueckspringen kann man nicht mehr, und ein Link, der zuverlaessig
     * in eine Absage fuehrt, ist schlechter als keiner.
     *
     * @return array<string, mixed>
     */
    public function frame(?Statement $statement, string $step, bool $editable = true): array
    {
        return [
            'sections' => array_map(
                fn (string $key): array => $this->section($editable ? $statement : null, $key),
                StatementFlow::keys(),
            ),
            'current' => $step,
            'title' => $this->translator->trans('billing.step.'.$step),
            'explanation' => $this->translator->trans('billing.explanation.'.$step),
            'position' => $this->translator->trans('flow.step_position', [
                '%position%' => StatementFlow::positionOf($step),
                '%count%' => StatementFlow::count(),
            ]),
            'heading' => $this->translator->trans('billing.statement.heading'),
            'subheading' => $this->name($statement),
            'hasPrevious' => null !== StatementFlow::previous($step),
            'isLast' => null === StatementFlow::next($step),
            'action' => null === $statement
                ? $this->urls->generate('app_billing_statement_new')
                : $this->urls->generate('app_billing_statement_edit', ['id' => $statement->id(), 'step' => $step]),
            'cancel' => $this->urls->generate('app_billing_statement'),
        ];
    }

    /**
     * Objekt und Jahr — und bei einer Korrektur, dass es eine ist.
     *
     * Der Hinweis gehoert ganz nach oben: eine Korrektur weist nur die
     * Differenz aus, und wer das nicht weiss, haelt die kleine Zahl fuer
     * einen Rechenfehler.
     */
    private function name(?Statement $statement): string
    {
        if (null === $statement) {
            return $this->translator->trans('billing.statement.new');
        }

        $name = $statement->propertyNumber().' · '.$statement->fiscalYear();

        return $statement->isCorrection()
            ? $name.' · '.$this->translator->trans('billing.statement.correction')
            : $name;
    }

    /**
     * @return array{key: string, label: string, url: string|null}
     */
    private function section(?Statement $statement, string $key): array
    {
        return [
            'key' => $key,
            'label' => $this->translator->trans('billing.step.'.$key),
            'url' => null === $statement ? null : $this->urls->generate(
                'app_billing_statement_edit',
                ['id' => $statement->id(), 'step' => $key],
            ),
        ];
    }
}
