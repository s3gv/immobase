<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Domain;

/**
 * Was ein Passwort erfuellen muss.
 *
 * Laenge und Unvorhersehbarkeit statt Zeichenklassen. Die Pflicht zu Ziffer
 * und Sonderzeichen erzeugt erfahrungsgemaess "Passwort1!" — formal erfuellt,
 * praktisch geraten. BSI und NIST empfehlen seit Jahren denselben Weg.
 *
 * Die Regeln stehen in der Domaene und nicht als Attribute an einem Formular:
 * sie gelten beim Einrichten, beim Zuruecksetzen und beim Aendern gleich, und
 * die Oberflaeche muss sie *vor* der Eingabe anzeigen koennen.
 */
final readonly class PasswordRules
{
    public const int MINIMUM_LENGTH = 12;

    /**
     * Die Regeln als Text, zum Anzeigen unter dem Feld.
     *
     * Sichtbar bevor jemand tippt, nicht erst als Fehlermeldung danach: eine
     * Regel, die man erst beim Scheitern erfaehrt, ist eine Falle.
     *
     * @return list<string>
     */
    public static function explained(bool $leakCheck): array
    {
        $rules = ['user.password.rule.length', 'user.password.rule.strength', 'user.password.rule.personal'];

        if ($leakCheck) {
            $rules[] = 'user.password.rule.leaks';
        }

        return $rules;
    }

    /**
     * Enthaelt das Passwort einen Bestandteil des eigenen Namens oder der
     * eigenen Adresse?
     *
     * Solche Passwoerter sind fuer jeden, der die Person kennt, in wenigen
     * Versuchen zu erraten — und die Adresse steht ohnehin auf der
     * Anmeldemaske.
     *
     * @param list<string> $personal
     */
    public static function containsPersonalData(string $password, array $personal): bool
    {
        $haystack = mb_strtolower($password);

        foreach (self::meaningful($personal) as $part) {
            if (str_contains($haystack, $part)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Nur Bestandteile ab vier Zeichen zaehlen.
     *
     * Ein Nachname wie "Li" steckt in unzaehligen harmlosen Passwoertern; die
     * Regel wuerde dann nur noch nerven, ohne etwas zu verhindern.
     *
     * @param list<string> $personal
     *
     * @return list<string>
     */
    private static function meaningful(array $personal): array
    {
        $parts = [];

        foreach ($personal as $entry) {
            $split = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($entry));

            foreach (false === $split ? [] : $split as $part) {
                if (mb_strlen($part) >= 4) {
                    $parts[] = $part;
                }
            }
        }

        return $parts;
    }
}
