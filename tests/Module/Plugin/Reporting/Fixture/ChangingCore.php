<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Plugin\Reporting\Fixture;

use Reporting\Source;

// Das Plugin teilt keinen Autoloader mit dem Core; seine Klassen werden
// einzeln geladen, auch hier.
require_once \dirname(__DIR__, 5).'/plugins/reporting/src/Source.php';

/**
 * Ein Core mit einem einzigen Objekt, dessen Name sich aendern laesst.
 *
 * `$whileFetching` laeuft einmal mitten im Abruf — nachdem die Objekte
 * gelesen sind, bevor die Einheiten drankommen. Genau dort aendert sich im
 * Ernstfall etwas, waehrend eine Spiegelung noch holt.
 */
final class ChangingCore implements Source
{
    public string $propertyName = 'alt';

    public int $fetches = 0;

    /** @var (callable(): void)|null */
    public $whileFetching;

    public function everything(string $resource): iterable
    {
        if ('properties' === $resource) {
            ++$this->fetches;

            return [['id' => 'p1', 'number' => 1, 'name' => $this->propertyName]];
        }

        if ('units' === $resource && null !== $this->whileFetching) {
            $hook = $this->whileFetching;
            $this->whileFetching = null;
            $hook();
        }

        return [];
    }
}
