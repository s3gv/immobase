<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Application;

use App\Module\Finance\Contract\PlanSources;
use App\Module\Finance\Domain\CostItemRepository;
use App\Module\Finance\Domain\DistributionKey;
use App\Module\Finance\Domain\DistributionKeyIsASystemKey;
use App\Module\Finance\Domain\DistributionKeyIsInUse;
use App\Module\Finance\Domain\DistributionKeyKind;
use App\Module\Finance\Domain\DistributionKeyRepository;
use App\Module\Finance\Domain\DistributionKeyShare;
use App\Module\Finance\Domain\RecordedInTheMeantime;
use App\Module\Finance\Domain\UnitBelongsElsewhere;
use App\Module\Finance\Domain\UsedByAPlan;
use App\Module\Property\Contract\UnitBrief;
use App\Module\Property\Contract\UnitDirectory;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use InvalidArgumentException;

/**
 * Die Verteilerschluessel pflegen.
 *
 * Eigene Schluessel gehoeren immer zu einem Objekt: „Anteile Tiefgarage"
 * ohne Haus ist keine Angabe. Die Systemschluessel bleiben, wie sie sind —
 * Flaeche ist ueberall Flaeche.
 */
final readonly class MaintainDistributionKeys
{
    public function __construct(
        private DistributionKeyRepository $keys,
        private CostItemRepository $items,
        private UnitDirectory $units,
        private PlanSources $plans,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    public function add(string $propertyId, string $name, DistributionKeyKind $kind): DistributionKey
    {
        $key = new DistributionKey($propertyId, $name, $kind);
        $this->keys->save($key);

        return $key;
    }

    /**
     * @throws DistributionKeyIsASystemKey
     * @throws InvalidArgumentException
     */
    public function rename(DistributionKey $key, string $name): void
    {
        $this->refuseSystemKey($key);
        $key->rename($name);
        $this->keys->save($key);
    }

    /**
     * Die festen Anteile setzen — je Einheit einer.
     *
     * Eine leere Angabe entfernt den Anteil: „nichts eingetragen" heisst,
     * dass die Einheit an diesem Schluessel nicht beteiligt ist, und das ist
     * etwas anderes als ein Anteil von null.
     *
     * @param array<string, string> $shares Kennung der Einheit auf Anteil
     *
     * @throws DistributionKeyIsASystemKey
     * @throws InvalidArgumentException
     */
    public function hold(DistributionKey $key, array $shares): void
    {
        $this->refuseSystemKey($key);
        $known = $this->units->byIds(array_keys($shares));
        $held = self::byUnit($key);

        foreach ($shares as $unitId => $share) {
            self::refuseForeignUnit($key, $known[$unitId] ?? null);
            self::set($key, $held[$unitId] ?? null, $unitId, $share);
        }

        $this->saved($key);
    }

    /**
     * Einen eigenen Schluessel entfernen.
     *
     * Nur, solange keine Kostenposition ihn benutzt. Der Fremdschluessel
     * haelt zwar ohnehin — aber als Datenbankfehler, und ein 500er ist keine
     * Antwort auf eine Frage, die man verstehen kann. Die Anwendung gibt die
     * lesbare Absage, die Datenbank bleibt die letzte Linie.
     *
     * @throws DistributionKeyIsASystemKey
     * @throws DistributionKeyIsInUse
     * @throws UsedByAPlan
     */
    public function drop(DistributionKey $key): void
    {
        $this->refuseSystemKey($key);

        if ($this->items->anyUsing($key->id())) {
            throw new DistributionKeyIsInUse();
        }

        // Und dieselbe Frage an die Wirtschaftsplaene: sie halten den
        // Schluessel unmittelbar und nicht ueber eine Kostenposition.
        $held = $this->plans->usedByAPlan([$key->id()])[$key->id()] ?? null;

        if (null !== $held) {
            throw UsedByAPlan::under($held);
        }

        $this->keys->remove($key);
    }

    /**
     * Ein Schluessel verteilt auf die Einheiten seines Objekts.
     *
     * Die Oberflaeche bietet nur die eigenen an — aber ein abgeschicktes
     * Formular ist Eingabe und keine Zusicherung. Ein untergeschobener
     * fremder Anteil stuende auf der Seite des Schluessels nirgends und
     * verteilte trotzdem mit.
     *
     * @throws UnitBelongsElsewhere
     */
    private static function refuseForeignUnit(DistributionKey $key, ?UnitBrief $unit): void
    {
        if (null === $unit || $unit->propertyId !== $key->propertyId()) {
            throw new UnitBelongsElsewhere();
        }
    }

    /**
     * Speichern — und die Absage der Datenbank in die des Fachs uebersetzen.
     *
     * Zwei gleichzeitig abgeschickte Formulare sehen beide noch keinen
     * Anteil zu einer Einheit und legen beide einen an; der eindeutige Index
     * faengt den zweiten. Die Anwendung gibt die lesbare Absage, die
     * Datenbank bleibt die letzte Linie.
     *
     * @throws RecordedInTheMeantime
     */
    private function saved(DistributionKey $key): void
    {
        try {
            $this->keys->save($key);
        } catch (UniqueConstraintViolationException) {
            throw new RecordedInTheMeantime();
        }
    }

    /**
     * Einen Anteil setzen, aendern oder entfernen.
     *
     * Aendern und nicht ersetzen: ein Entfernen und ein Anlegen in derselben
     * Runde laufen in den eindeutigen Index, weil Doctrine erst einfuegt und
     * dann loescht. Derselbe Fallstrick wie bei den Verbrauchswerten.
     *
     * @throws InvalidArgumentException
     */
    private static function set(
        DistributionKey $key,
        ?DistributionKeyShare $existing,
        string $unitId,
        string $share,
    ): void {
        if ('' === trim($share)) {
            if (null !== $existing) {
                $key->remove($existing);
            }

            return;
        }

        if (null === $existing) {
            new DistributionKeyShare($key, $unitId, $share);

            return;
        }

        $existing->hold($share);
    }

    /**
     * Die vorhandenen Anteile nach Einheit — so liest sie der Abgleich.
     *
     * @return array<string, DistributionKeyShare>
     */
    private static function byUnit(DistributionKey $key): array
    {
        $held = [];

        foreach ($key->shares() as $share) {
            $held[$share->unitId()] = $share;
        }

        return $held;
    }

    /**
     * @throws DistributionKeyIsASystemKey
     */
    private function refuseSystemKey(DistributionKey $key): void
    {
        if ($key->isSystem()) {
            throw new DistributionKeyIsASystemKey();
        }
    }
}
