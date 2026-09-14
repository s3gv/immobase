<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Audit;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Wohin ein protokollierter Datensatz fuehrt.
 *
 * Das Protokoll haelt die Art des Datensatzes und seine Kennung — mehr weiss
 * es nicht und soll es nicht wissen. Wohin man klickt, weiss das Modul, dem
 * der Datensatz gehoert; dieselbe Richtung wie bei
 * {@see \App\Shared\Search\SearchesRecords}: **kein Modul haengt am
 * Protokoll.**
 *
 * Gefragt wird fuer eine ganze Seite auf einmal. Eine Schnittstelle, die nur
 * einzeln antwortet, zwingt jeden Aufrufer zu fuenfzig Abfragen — genau das
 * Muster, das Listen langsam macht.
 *
 * Was kein Modul beantwortet, steht ohne Verweis da. Das ist kein Fehler: die
 * Kennung eines geloeschten Datensatzes fuehrt nirgendwohin, und dafuer steht
 * sie ja da.
 */
#[AutoconfigureTag('audit.links')]
interface LinksToRecords
{
    /**
     * Die Arten, zu denen dieses Modul den Weg kennt — Klassennamen ohne
     * Namensraum, so wie das Protokoll sie festhaelt.
     *
     * @return list<string>
     */
    public function handles(): array;

    /**
     * @param list<string> $ids
     *
     * @return array<string, string> Kennung auf Adresse; unbekannte fehlen
     */
    public function urlsFor(string $record, array $ids): array;
}
