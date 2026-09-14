<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\UserInterface\Controller;

use App\Module\Finance\Domain\Loan;
use App\Shared\Ui\Sort;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Die Abschnitte der Darlehensseite.
 *
 * Die beiden Schritte des Ablaufs — und zwei mehr, die nicht ins Anlegen
 * gehoeren: der **Tilgungsplan** wird gerechnet und nicht eingegeben, und der
 * **Verlauf** entsteht erst Jahre spaeter. Eine Sondertilgung ist nichts, was
 * man beim Aufnehmen eintraegt.
 *
 * Getrennt und nicht untereinander: ein Abschnitt beantwortet eine Frage.
 * „Was ist vereinbart" und „was folgt daraus" sind zwei.
 */
final readonly class LoanPage
{
    /** Was aus den Konditionen folgt — gelesen, nicht eingegeben. */
    public const string SCHEDULE = 'tilgungsplan';

    public const string HISTORY = 'verlauf';

    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return non-empty-list<string>
     */
    public static function keys(): array
    {
        return [...LoanFlow::keys(), self::SCHEDULE, self::HISTORY];
    }

    /** Ein unbekannter Abschnitt faellt auf den ersten zurueck — Eingabe, kein Fehler. */
    public static function known(string $requested): string
    {
        return \in_array($requested, self::keys(), true) ? $requested : LoanFlow::BASICS;
    }

    public static function name(string $key): string
    {
        return match ($key) {
            self::SCHEDULE => 'schedule',
            self::HISTORY => 'history',
            default => LoanFlow::name($key),
        };
    }

    /**
     * Je sortierbarer Spalte die Adresse, die ein Klick ergibt.
     *
     * Fertig gebaut und nicht in der Vorlage zusammengesetzt: nur hier ist
     * bekannt, welche Filter mitgehen muessen.
     *
     * @param list<string> $fields
     *
     * @return array<string, string>
     */
    public function sortUrls(Request $request, Sort $sort, array $fields): array
    {
        $urls = [];

        foreach ($fields as $field) {
            $urls[$field] = $this->urls->generate('app_finance_loan', [
                ...self::query($request),
                'sortieren' => $field,
                'richtung' => $sort->nextDirectionFor($field),
            ]);
        }

        return $urls;
    }

    /**
     * Die Adresse der Liste mit __PAGE__ fuer die Seitenzahl.
     *
     * Die Filter muessen mitwandern: eine Seite zwei ohne Filter zeigt etwas
     * anderes als Seite eins mit Filter.
     */
    public function listUrl(Request $request): string
    {
        return $this->urls->generate('app_finance_loan', [...self::query($request), 'page' => '__PAGE__']);
    }

    /**
     * @return array<string, mixed>
     */
    public function sections(Loan $loan, string $current): array
    {
        $number = $this->translator->trans('finance.loan.number', ['%number%' => $loan->number()]);

        return [
            'current' => $current,
            'sections' => $this->listOf($loan),
            'heading' => $number,
            'subheading' => '' === $loan->label() ? $loan->lender() : $loan->label(),
            'title' => $this->translator->trans('finance.loan.section.'.self::name($current)),
            'explanation' => $this->translator->trans('finance.loan.explanation.'.self::name($current)),
            // Die beiden gerechneten Abschnitte tragen Tabellen und keine
            // Formulare — sie bekommen die ganze Breite.
            'wide' => \in_array($current, [self::SCHEDULE, self::HISTORY], true),
            'trail' => [
                ['label' => 'ImmoBase', 'url' => $this->urls->generate('app_dashboard')],
                [
                    'label' => $this->translator->trans('finance.loan.heading'),
                    'url' => $this->urls->generate('app_finance_loan'),
                ],
                ['label' => $number, 'url' => null],
            ],
        ];
    }

    /**
     * Die Abschnitte mit ihren Adressen — alle erreichbar.
     *
     * Anders als im Ablauf: dort ist der naechste Schritt erst erreichbar,
     * wenn es etwas zu speichern gibt. Hier steht alles schon da.
     *
     * @return list<array{key: string, label: string, url: string}>
     */
    private function listOf(Loan $loan): array
    {
        return array_map(fn (string $key): array => [
            'key' => $key,
            'label' => $this->translator->trans('finance.loan.section.'.self::name($key)),
            'url' => $this->urls->generate('app_finance_loan_show', [
                'number' => $loan->number(),
                'abschnitt' => $key,
            ]),
        ], self::keys());
    }

    /**
     * Die Filter der Adresszeile, leere weggelassen.
     *
     * @return array<string, string>
     */
    private static function query(Request $request): array
    {
        $parameters = [];

        foreach (['objekt', 'q', 'sortieren', 'richtung'] as $name) {
            $value = trim($request->query->getString($name));

            if ('' !== $value) {
                $parameters[$name] = $value;
            }
        }

        return $parameters;
    }
}
