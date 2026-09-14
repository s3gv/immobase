<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Controller;

use App\Module\Billing\Application\ComposeStatement;
use App\Module\Billing\Application\DraftSelection;
use App\Module\Billing\Domain\Statement;
use App\Module\Billing\Domain\StatementKinds;
use App\Module\Billing\Domain\StatementRepository;
use Symfony\Component\HttpFoundation\Request;

/**
 * Was ein Schritt entgegennimmt.
 *
 * Je Schritt eine Methode, gemeinsam ist nur die Form: eine Liste von Fehlern
 * je Feld, leer heisst gespeichert. Der letzte Schritt nimmt nichts entgegen
 * — er ist zum Ansehen da.
 */
final readonly class StatementStepInput
{
    public function __construct(
        private StatementRepository $statements,
        private DraftSelection $selection,
        private ComposeStatement $compose,
    ) {
    }

    /**
     * @return array<string, string> Feldname auf Uebersetzungsschluessel
     */
    public function apply(string $step, Request $request, Statement $statement): array
    {
        $steps = [
            StatementFlow::BASICS => $this->basics(...),
            StatementFlow::ADVANCES => $this->advances(...),
            StatementFlow::COSTS => $this->costs(...),
            StatementFlow::RECIPIENTS => $this->recipients(...),
        ];

        return isset($steps[$step]) ? $steps[$step]($request, $statement) : [];
    }

    /** @return array<string, string> */
    private function basics(Request $request, Statement $statement): array
    {
        $statement->describe(
            $request->request->getString('label'),
            StatementKinds::of(
                $request->request->getBoolean('forOwners'),
                $request->request->getBoolean('forTenants'),
            ),
        );
        $this->statements->save($statement);

        return [];
    }

    /** @return array<string, string> */
    private function advances(Request $request, Statement $statement): array
    {
        $chosen = $this->selection->of($statement);
        $this->selection->keep($statement, $chosen['costs'], self::ticked($request, 'payments'));

        return [];
    }

    /** @return array<string, string> */
    private function costs(Request $request, Statement $statement): array
    {
        $chosen = $this->selection->of($statement);
        $this->selection->keep($statement, self::ticked($request, 'costs'), $chosen['payments']);

        return [];
    }

    /**
     * Wer kein PDF bekommt — gespeichert werden die Abwahlen.
     *
     * @return array<string, string>
     */
    private function recipients(Request $request, Statement $statement): array
    {
        $chosen = $this->selection->of($statement);
        $wanted = self::ticked($request, 'pdf');
        $without = [];

        foreach ($this->compose->of($statement, $chosen['costs'], $chosen['payments'])->documents as $document) {
            if (!\in_array($document->key(), $wanted, true)) {
                $without[] = $document->key();
            }
        }

        $this->statements->keepWithoutPdf($statement->id(), $without);

        return [];
    }

    /**
     * @return list<string>
     */
    private static function ticked(Request $request, string $field): array
    {
        $values = $request->request->all($field);

        return array_values(array_filter(
            array_map(static fn (mixed $value): string => \is_string($value) ? $value : '', $values),
            static fn (string $value): bool => '' !== $value,
        ));
    }
}
