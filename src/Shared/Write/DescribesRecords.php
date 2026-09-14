<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Write;

use Doctrine\Persistence\Proxy;
use ReflectionMethod;

/**
 * Wie ein beliebiger Datensatz im Protokoll heisst.
 *
 * **Geraten und nicht gefragt.** Das Schreibsignal haengt an Doctrine und
 * sieht damit jede Entitaet der Anwendung — auch die, die es nie
 * kennenlernen wird.
 * Eine Schnittstelle, die jede Entitaet umsetzen muesste, waere ein Eingriff
 * in acht Module fuer eine Zeile Text; und die erste Entitaet, die sie
 * vergisst, staende ohne Bezeichnung da.
 *
 * Also wird der Reihe nach gefragt, was es gaebe: `displayName`, `label`,
 * `name`, `subject`, `title`. Findet sich nichts, bleibt die Zeile bei Art
 * und Kennung — das ist wenig, aber es stimmt. Was hier steht, wird
 * eingefroren; ein geloeschter Datensatz laesst sich nicht mehr nachschlagen.
 */
final readonly class DescribesRecords
{
    /** In dieser Reihenfolge — die erste, die es gibt, gewinnt. */
    private const array NAMES = ['displayName', 'label', 'name', 'subject', 'title'];

    /** Und das haengt als Nummer dahinter, wenn es sie gibt. */
    private const array NUMBERS = ['reference', 'number'];

    /**
     * Die Art, ohne Namensraum: „Party", „Statement".
     *
     * Ueber die echte Klasse und nicht ueber die vorliegende: Doctrine
     * schiebt Stellvertreter unter, und „PartyProxy" waere eine Art, die es
     * nirgends gibt.
     */
    public function kindOf(object $entity): string
    {
        $class = $this->classOf($entity);
        $at = strrpos($class, '\\');

        return false === $at ? $class : substr($class, $at + 1);
    }

    /**
     * Die volle Klasse — die echte, nicht die vorliegende.
     *
     * Gebraucht, wo eine Entitaet einer Ressource zugeordnet wird: Doctrine
     * schiebt Stellvertreter unter, und die Zuordnung liefe dann ins Leere.
     *
     * @return class-string
     */
    public function classOf(object $entity): string
    {
        $class = $entity instanceof Proxy ? get_parent_class($entity) : $entity::class;

        /** @var class-string $class */
        $class = false === $class ? $entity::class : $class;

        return $class;
    }

    public function idOf(object $entity): string
    {
        return self::stringFrom($entity, 'id') ?? '';
    }

    public function labelOf(object $entity): string
    {
        $name = null;

        foreach (self::NAMES as $method) {
            $name ??= self::stringFrom($entity, $method);
        }

        $number = null;

        foreach (self::NUMBERS as $method) {
            $number ??= self::stringFrom($entity, $method);
        }

        return trim(($number ?? '').' '.($name ?? ''));
    }

    /**
     * Der Rueckgabewert einer Methode, wenn es sie gibt und sie etwas Kurzes
     * liefert.
     *
     * Nur Zeichenketten und Zahlen. Eine Methode, die ein Wertobjekt oder
     * eine Sammlung zurueckgibt, koennte nachladen — und ein Protokoll, das
     * beim Schreiben Abfragen ausloest, macht jedes Speichern langsamer.
     */
    private static function stringFrom(object $entity, string $method): ?string
    {
        if (!method_exists($entity, $method)) {
            return null;
        }

        // Ueber die Spiegelung und nicht ueber `$entity->$method()`: welcher
        // Typ dort herauskaeme, weiss beim Schreiben niemand, und ein Aufruf
        // mit geratenem Namen laesst sich nicht statisch pruefen. So steht
        // wenigstens fest, dass die Methode oeffentlich ist und ohne
        // Argumente auskommt.
        $found = new ReflectionMethod($entity, $method);

        if (!$found->isPublic() || $found->getNumberOfRequiredParameters() > 0) {
            return null;
        }

        $value = $found->invoke($entity);

        if (\is_string($value)) {
            return '' === $value ? null : $value;
        }

        return \is_int($value) ? (string) $value : null;
    }
}
