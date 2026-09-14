<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Domain;

use App\Shared\Contact\Email;
use App\Shared\Ui\Page;

/**
 * Zugriff auf Benutzer.
 *
 * Das Interface liegt in Domain, die Doctrine-Umsetzung in Infrastructure.
 * Kein anderes Modul darf beides benutzen — dafuer gibt es Contract.
 */
interface UserRepository
{
    public function save(User $user): void;

    public function remove(User $user): void;

    public function findByEmail(Email $email): ?User;

    public function byId(string $id): ?User;

    public function byNumber(int $number): ?User;

    /**
     * Das Portalkonto einer Partei — hoechstens eines.
     *
     * Ein Konto je Person und nicht je Rolle: wer Eigentuemer *und* Mieter
     * ist, meldet sich einmal an und sieht beides.
     */
    public function forParty(string $partyId): ?User;

    /**
     * Die naechste freie Kontonummer, ab 1001.
     *
     * Aus einer Sequenz und nicht aus MAX(number) + 1: zwei gleichzeitige
     * Einladungen lesen sonst denselben Hoechststand, und die zweite laeuft in
     * den eindeutigen Index.
     */
    public function nextNumber(): int;

    public function countMatching(UserFilter $filter): int;

    /**
     * @return list<User>
     */
    public function matching(UserFilter $filter, Page $page): array;

    /**
     * Wie viele aktive Konten haben dieses Recht — ueber eine Rolle, ueber die
     * Systemrolle oder direkt?
     *
     * Als Abfrage und nicht als Schleife ueber alle Konten: die Frage steht
     * vor jedem Deaktivieren und jedem Loeschen, und sie darf nicht mit der
     * Zahl der Benutzer teurer werden.
     *
     * @param string|null $exceptUserId ein Konto, das nicht mitzaehlt — damit
     *                                  sich fragen laesst, ob ausser ihm noch
     *                                  jemand verwalten kann
     */
    public function countActiveManagers(string $permissionKey, ?string $exceptUserId = null): int;

    /**
     * Fuehrt eine Aenderung aus, die jemandem die Benutzerverwaltung nehmen
     * kann — unter einer Sperre auf den Konten, die sie heute haben.
     *
     * Ohne sie zaehlen zwei gleichzeitige Anfragen beide zwei Verwalter,
     * halten beide ihr Konto fuer entbehrlich und schalten beide ab. Danach
     * kommt niemand mehr an die Verwaltung, und bei Selbst-Hosting gibt es
     * keinen Support, der es richtet.
     *
     * @template T
     *
     * @param callable(): T $change
     *
     * @return T
     */
    public function guardingManagers(string $permissionKey, callable $change): mixed;

    /**
     * Setzt das verbrauchte Zeitfenster des zweiten Faktors — aber nur, wenn
     * es vorwaerts geht.
     *
     * Ein Code gilt einmal. Ohne bedingtes Schreiben kaemen zwei gleichzeitige
     * Anmeldungen mit demselben abgefangenen Code beide durch: beide lesen
     * dasselbe alte Fenster, beide halten sich fuer den ersten.
     *
     * @return bool ob dieser Aufruf das Fenster verbraucht hat
     */
    public function consumeTotpStep(User $user, SecondFactorSettings $accepted): bool;
}
