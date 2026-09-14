<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Application;

use App\Module\Finance\Contract\PlanSources;
use App\Module\Finance\Domain\CostKind;
use App\Module\Finance\Domain\CostKindIsPartOfTheCatalogue;
use App\Module\Finance\Domain\CostKindRepository;
use App\Module\Finance\Domain\UsedByAPlan;
use InvalidArgumentException;

/**
 * Die Kostenarten pflegen.
 */
final readonly class MaintainCostKinds
{
    public function __construct(
        private CostKindRepository $kinds,
        private PlanSources $plans,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    public function add(string $name, bool $apportionable): CostKind
    {
        $kind = new CostKind($name, $apportionable, self::behindTheOthers($this->kinds->all()));
        $this->kinds->save($kind);

        return $kind;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function rename(CostKind $kind, string $name, bool $apportionable): void
    {
        $kind->rename($name, $apportionable);
        $this->kinds->save($kind);
    }

    /**
     * Eine eigene Art entfernen.
     *
     * Nur, solange kein Wirtschaftsplan auf ihr steht. Der Fremdschluessel
     * haelt zwar ohnehin — aber als Datenbankfehler, und ein 500er ist keine
     * Antwort auf eine Frage, die man verstehen kann. Die Anwendung gibt die
     * lesbare Absage, die Datenbank bleibt die letzte Linie.
     *
     * @throws CostKindIsPartOfTheCatalogue
     * @throws UsedByAPlan
     */
    public function drop(CostKind $kind): void
    {
        if ($kind->isSystem()) {
            throw new CostKindIsPartOfTheCatalogue();
        }

        $held = $this->plans->usedByAPlan([$kind->id()])[$kind->id()] ?? null;

        if (null !== $held) {
            throw UsedByAPlan::under($held);
        }

        $this->kinds->remove($kind);
    }

    /**
     * Eine eigene Art steht hinter den mitgelieferten.
     *
     * Die Reihenfolge der BetrKV ist die gewohnte; wer eine eigene anlegt,
     * sucht sie ohnehin am Ende.
     *
     * @param list<CostKind> $existing
     */
    private static function behindTheOthers(array $existing): int
    {
        return array_reduce(
            $existing,
            static fn (int $highest, CostKind $kind): int => max($highest, $kind->ordering()),
            0,
        ) + 1;
    }
}
