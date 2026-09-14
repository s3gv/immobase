<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Settings\Contract;

/**
 * Die einzige Flaeche, ueber die andere Module Einstellungen lesen.
 *
 * Bewusst nur Primitive und eine Vorgabe je Abfrage: ein fremdes Modul soll
 * weder die Entity dieses Moduls kennen noch sich darauf verlassen muessen,
 * dass ein Wert ueberhaupt gesetzt ist. Eine frische Installation hat keine
 * Zeile in der Tabelle, und das ist kein Fehlerfall.
 */
interface ApplicationSettings
{
    /**
     * Prueft die Anwendung Passwoerter gegen bekannte Leaks?
     *
     * Der Name steht hier und nicht in der Umsetzung: wer die Einstellung
     * liest, soll dafuer nicht das halbe Modul importieren muessen — und ein
     * Tippfehler in einer frei geschriebenen Zeichenkette faellt erst auf,
     * wenn eine Einstellung wirkungslos bleibt.
     */
    public const string LEAK_CHECK = 'security.password_leak_check';

    /**
     * Verzichtet die Installation auf Bewegung?
     *
     * Die Einstellung kann Bewegung nur wegnehmen, nie erzwingen: wer im
     * System `prefers-reduced-motion` gesetzt hat, bekommt weiter Ruhe. Eine
     * Anwendung, die eine Barrierefreiheits-Angabe ueberstimmt, weiss es
     * besser als der Mensch davor.
     */
    public const string REDUCED_MOTION = 'app.reduced_motion';

    /**
     * Die Fristen, Kosten und Saetze des Mahnwesens.
     *
     * Sie stehen hier aus demselben Grund wie {@see self::LEAK_CHECK}: wer sie
     * liest, soll dafuer nicht das halbe Modul importieren muessen, und wer
     * sie bearbeitet, findet sie an derselben Stelle. Zahlen, keine Texte —
     * ein Mahntext waere laenger, als eine Einstellung fasst, und stuende
     * abgeschnitten auf dem Blatt.
     *
     * Betraege in Cent, Zinssaetze in Basispunkten, Fristen in Tagen.
     */
    public const string DUNNING_DAYS_REMINDER = 'dunning.days.reminder';
    public const string DUNNING_DAYS_FIRST = 'dunning.days.first';
    public const string DUNNING_DAYS_FINAL = 'dunning.days.final';
    public const string DUNNING_COSTS_FIRST = 'dunning.costs.first';
    public const string DUNNING_COSTS_FINAL = 'dunning.costs.final';
    public const string DUNNING_POINTS_CONSUMER = 'dunning.points.consumer';
    public const string DUNNING_POINTS_COMMERCIAL = 'dunning.points.commercial';
    public const string DUNNING_FLAT_FEE = 'dunning.flat_fee';

    /**
     * Wie lange eine im Portal hochgeladene Datei aufbewahrt wird.
     *
     * Eine Zusage und keine Einschraenkung: wer eine Datei anhaengt, weiss,
     * dass sie wieder verschwindet. Hoechstens eine Woche — laenger waere ein
     * Archiv, und ein Archiv will gepflegt werden.
     */
    public const string PORTAL_FILE_RETENTION_DAYS = 'portal.file_retention_days';

    /**
     * Wie lange eine ungelesene Nachricht wartet, bevor eine Mail hinausgeht.
     */
    public const string PORTAL_NOTIFY_AFTER_MINUTES = 'portal.notify_after_minutes';

    /**
     * Die Grenzen der beiden Portalwerte — hier und nicht im Formular.
     *
     * Sie stehen neben ihren Schluesseln, weil zwei Seiten sie brauchen: das
     * Formular, damit es nichts anderes annimmt, und das lesende Modul, damit
     * ein Wert, der auf einem anderen Weg hereinkam, ebenso begrenzt ist.
     * Zwei Listen von Grenzen liefen auseinander, und die Abweichung faende
     * niemand.
     */
    public const int PORTAL_FILE_RETENTION_MIN = 1;
    public const int PORTAL_FILE_RETENTION_MAX = 7;
    public const int PORTAL_FILE_RETENTION_DEFAULT = 3;

    public const int PORTAL_NOTIFY_AFTER_MIN = 1;
    public const int PORTAL_NOTIFY_AFTER_MAX = 120;
    public const int PORTAL_NOTIFY_AFTER_DEFAULT = 10;

    public function bool(string $key, bool $default): bool;

    /**
     * Eine ganze Zahl — Tage, Cent, Basispunkte.
     *
     * Was nicht als ganze Zahl dasteht, gilt als nicht gesetzt und faellt auf
     * die Vorgabe zurueck. Eine halb gelesene Einstellung waere schlimmer als
     * keine: sie saehe aus, als haette jemand sie so gewollt.
     */
    public function int(string $key, int $default): int;

    /**
     * Wer hier schreibt — fuer Briefkoepfe und Abrechnungen.
     *
     * Eine Frage und ein fertiges Ergebnis: ein Modul, das einen Briefkopf
     * setzt, soll nicht vierzehn Schluessel kennen und selbst
     * zusammensetzen, was vollstaendig ist.
     */
    public function organisation(): Organisation;

    /**
     * Das Logo — oder nichts.
     *
     * Die Bytes unmittelbar und nicht als Adresse: ein PDF entsteht auf dem
     * Server und braucht keinen Umweg ueber HTTP.
     */
    public function logo(): ?LogoImage;
}
