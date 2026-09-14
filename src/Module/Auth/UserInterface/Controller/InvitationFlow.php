<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\UserInterface\Controller;

use App\Module\Auth\Domain\User;
use App\Shared\Flow\FlowDefinition;
use App\Shared\Flow\FlowState;
use App\Shared\Flow\FlowStep;

/**
 * Woraus das Einrichten eines Kontos besteht.
 *
 * Anders als beim Stammdatenablauf haelt hier nichts einen Zwischenstand in
 * der Sitzung: jeder Schritt speichert sofort am Konto. Wer zwischendurch das
 * Fenster schliesst, macht spaeter mit demselben Link dort weiter, wo er war —
 * eine abgelaufene Sitzung darf niemanden zwingen, von vorn zu beginnen.
 *
 * Der Preis dafuer ist, dass ein halb eingerichtetes Konto existiert. Das ist
 * hinnehmbar: ohne Passwort kommt es ohnehin nicht herein, und mit Passwort
 * ist es genau das, was der Eingeladene wollte.
 */
final class InvitationFlow
{
    public const string PASSWORD = 'password';
    public const string PROFILE = 'profile';
    public const string FACTOR = 'factor';

    private function __construct()
    {
    }

    /**
     * Ein bis drei Schritte — je nachdem, wer eingeladen wurde.
     *
     * **Ein Portalkonto wird nicht nach seinem Namen gefragt.** Den kennt die
     * Verwaltung bereits, er steht in den Stammdaten, und ein zweiter daneben
     * waere eine zweite Wahrheit ueber denselben Menschen. Nach der
     * „Taetigkeit" erst recht nicht — ein Mieter hat bei uns keine.
     *
     * **Den zweiten Faktor bietet der Ablauf nur an, wo noch keiner ist.**
     * Beim Zuruecksetzen hat das Konto oft schon einen, und den ersetzt kein
     * Link aus einer E-Mail: sonst stuende der Faktor genau dann nicht mehr
     * im Weg, wenn jemand an das Postfach gekommen ist.
     */
    public static function definition(?User $user = null, bool $offerFactor = true): FlowDefinition
    {
        $steps = [new FlowStep(self::PASSWORD, 'user.step.password.label', 'user.step.password.explanation')];

        if (null === $user || !$user->isPortalAccount()) {
            $steps[] = new FlowStep(self::PROFILE, 'user.step.profile.label', 'user.step.profile.explanation');
        }

        if ($offerFactor) {
            $steps[] = new FlowStep(
                self::FACTOR,
                'user.step.factor.label',
                'user.step.factor.explanation',
                'user.step.factor.title',
            );
        }

        return new FlowDefinition('invitation', $steps);
    }

    /**
     * Wo im Ablauf jemand gerade steht.
     *
     * Abgeleitet aus dem, was schon eingetragen ist, und nicht aus einem
     * Zaehler: so kommt man nach einer Unterbrechung an derselben Stelle
     * wieder heraus.
     *
     * Ob das Passwort erledigt ist, entscheidet der Aufrufer und nicht diese
     * Klasse. Beim Einrichten genuegt die Frage, ob ueberhaupt eines gesetzt
     * ist. Beim Zuruecksetzen nicht: dort *gibt* es eines, und trotzdem ist
     * genau das der Schritt, um den es geht.
     */
    public static function stepFor(User $user, bool $passwordDone): string
    {
        if (!$passwordDone) {
            return self::PASSWORD;
        }

        if ($user->isPortalAccount()) {
            return self::FACTOR;
        }

        return $user->name()->isKnown() ? self::FACTOR : self::PROFILE;
    }

    /**
     * Der Zustand fuer die Anzeige: erledigt ist, was vor dem aktuellen
     * Schritt liegt.
     */
    public static function stateFor(FlowDefinition $definition, string $current): FlowState
    {
        $visited = [];

        foreach ($definition->steps() as $step) {
            $visited[] = $step->key;

            if ($step->key === $current) {
                break;
            }
        }

        return FlowState::fromArray(['current' => $current, 'values' => [], 'visited' => $visited]);
    }
}
