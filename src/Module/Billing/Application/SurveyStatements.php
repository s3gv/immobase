<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\Statement;
use App\Module\Billing\Domain\StatementDocument;
use App\Module\Billing\Domain\StatementFilter;
use App\Module\Billing\Domain\StatementKind;
use App\Module\Billing\Domain\StatementRepository;
use App\Shared\Money\Money;
use DateTimeImmutable;

/**
 * Was auf der Abrechnungsuebersicht steht.
 *
 * Die Kennzahlen, die eine Verwaltung morgens sehen will — und eine, die sie
 * nicht sehen will, aber muss: die Frist. Eine Nebenkostenabrechnung muss dem
 * Mieter bis zum 31. Dezember des Folgejahres zugehen (§ 556 Abs. 3 BGB);
 * danach ist die Nachforderung verloren. Eine Anwendung, die das weiss und
 * schweigt, ist mitschuldig.
 */
final readonly class SurveyStatements
{
    public function __construct(
        private StatementRepository $statements,
        private CheckCorrections $corrections,
    ) {
    }

    /**
     * @return array{drafts: int, released: int, balance: Money, credits: Money, overdue: list<Statement>, deadline: int, corrections: array<string, int>, correctable: list<Statement>}
     */
    public function overview(): array
    {
        $released = $this->statements->released();
        $pending = $this->corrections->pending();
        $sums = self::sums($released);

        return [
            'drafts' => $this->statements->countMatching(StatementFilter::draftsOnly()),
            'released' => \count($released),
            'balance' => $sums['due'],
            'credits' => $sums['credits'],
            'overdue' => self::overdue($released),
            'deadline' => self::lastYearToSettle(),
            'corrections' => $pending,
            'correctable' => array_values(array_filter(
                $released,
                static fn (Statement $statement): bool => isset($pending[$statement->id()]),
            )),
        ];
    }

    /**
     * Das Jahr, dessen Nebenkostenabrechnungen dieses Jahr hinaus muessen.
     *
     * Ab Oktober wird es dringend; davor ist es eine Angabe, keine Mahnung.
     */
    public static function lastYearToSettle(): int
    {
        return (int) (new DateTimeImmutable('today'))->format('Y') - 1;
    }

    /**
     * Nachzahlungen und Guthaben, getrennt gezaehlt.
     *
     * Beides zusammen ergaebe eine Zahl, die nichts sagt: hundert Euro
     * Nachzahlung und hundert Euro Guthaben sind nicht null, sondern zwei
     * Vorgaenge.
     *
     * @param list<Statement> $released
     *
     * @return array{due: Money, credits: Money} beide positiv
     */
    private static function sums(array $released): array
    {
        $due = Money::zero();
        $credits = Money::zero();

        foreach ($released as $statement) {
            foreach ($statement->documents() as $document) {
                $amount = $document->balance();

                // Beide Summen positiv: die Beschriftung sagt die Richtung,
                // die Zahl die Groesse. „-23.271,84 € Guthaben" liest sich
                // wie eine Schuld — dieselbe Haltung wie bei den
                // Verbindlichkeiten im Vermoegensbericht.
                if ($amount->isNegative()) {
                    $credits = $credits->minus($amount);
                } else {
                    $due = $due->plus($amount);
                }
            }
        }

        return ['due' => $due, 'credits' => $credits];
    }

    /**
     * Objekte, fuer die die Frist laeuft — hier: Laeufe, die es schon gibt.
     *
     * Was **fehlt**, laesst sich erst sagen, wenn Billing weiss, welche
     * Objekte ueberhaupt Mieter haben; das steht in der Empfaengerpruefung
     * und kommt mit ihr.
     *
     * @param list<Statement> $released
     *
     * @return list<Statement>
     */
    private static function overdue(array $released): array
    {
        $year = self::lastYearToSettle();

        return array_values(array_filter(
            $released,
            static fn (Statement $statement): bool => $statement->fiscalYear() < $year
                && self::hasTenants($statement),
        ));
    }

    /** @param Statement $statement */
    private static function hasTenants(Statement $statement): bool
    {
        foreach ($statement->documents() as $document) {
            if (self::isForTenant($document)) {
                return true;
            }
        }

        return false;
    }

    private static function isForTenant(StatementDocument $document): bool
    {
        return StatementKind::OperatingCosts === $document->kind();
    }
}
