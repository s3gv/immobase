<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Api;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Was ein Modul nach draussen gibt.
 *
 * Dieselbe Bauart wie bei {@see \App\Shared\Figure\ContributesFigures} und
 * {@see \App\Shared\Search\SearchesRecords}: das Modul meldet sich an, die
 * Schnittstelle fragt, wer sich gemeldet hat. **Kein Modul haengt vom
 * Api-Modul ab.**
 *
 * **Der Name ist englisch.** Er ist die einzige Flaeche, die fremde
 * Entwickler lesen, und der Code ist ohnehin englisch; die deutschen Adressen
 * der Oberflaeche bleiben davon unberuehrt.
 *
 * **Das Recht ist dasselbe wie in der Oberflaeche.** Wer „Kosten" ueber die
 * Schnittstelle liest, braucht `finance.view` — sonst gaebe es zwei
 * Wahrheiten darueber, wer was sehen darf, und die zweite waere die
 * unbewachte.
 */
#[AutoconfigureTag('api.resource')]
interface PublishesResource
{
    /** Kleinbuchstaben und Bindestriche: `properties`, `cost-kinds`. */
    public function name(): string;

    /** Der Rechteschluessel, den ein Token dafuer haben muss. */
    public function permission(): string;

    /**
     * Gehoert dieser geschriebene Datensatz zu dieser Ressource — und unter
     * welcher Kennung?
     *
     * Das Bindeglied zu den Ereignissen: was hier eine Kennung bekommt, loest
     * einen Webhook aus, und zwar nur an Plugins, die diese Ressource lesen
     * duerfen. Was keiner Ressource gehoert, loest keines aus — so kann durch
     * die Hintertuer nichts hinausgehen, was vorne niemand abrufen koennte.
     *
     * **Auch die Teile zaehlen.** Ein Jahreswert ist keine eigene Ressource,
     * er steht in der Kostenposition — und wer ihn aendert, aendert damit
     * das, was unter `/api/v1/costs/<id>` steht. Zurueckgegeben wird deshalb
     * die Kennung des Ganzen und nicht die des Teils: ihr folgt das Plugin.
     */
    public function identifies(object $entity): ?string;

    public function page(ApiQuery $query): ApiPage;

    /**
     * Ein einzelner Datensatz — der Weg, dem ein Webhook folgt.
     *
     * @return array<string, mixed>|null
     */
    public function one(string $id): ?array;
}
