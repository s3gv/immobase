<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Application;

use App\Module\Property\Domain\Measures;
use App\Module\Property\Domain\Unit;
use App\Module\Property\Domain\UnitRepository;
use App\Module\Property\Domain\UnitUsage;
use App\Shared\Change\ChangeableField;
use App\Shared\Change\FieldKind;
use App\Shared\Change\RecordKind;
use App\Shared\Change\RecordThatMayChange;
use InvalidArgumentException;

/**
 * Was sich an einer Einheit vorschlagen laesst: wie sie heisst, wozu sie da
 * ist, wie gross sie ist.
 *
 * **Der Miteigentumsanteil steht nicht dabei.** Er kommt aus der
 * Teilungserklaerung, nicht aus einer Meinung — und an ihm haengt jede
 * Abrechnung des Objekts. Ebenso wenig der Verwaltungszustand: ob eine
 * Einheit noch verwaltet wird, entscheidet ein Vertrag.
 */
final readonly class ChangeableUnit implements RecordThatMayChange
{
    public function __construct(private UnitRepository $units)
    {
    }

    public function kind(): RecordKind
    {
        return RecordKind::Unit;
    }

    public function fieldsOf(string $id): array
    {
        $unit = $this->units->byId($id);

        if (null === $unit) {
            return [];
        }

        return [
            new ChangeableField('label', 'property.unit.field.label', FieldKind::Text, $unit->label()),
            new ChangeableField(
                'usage',
                'property.unit.field.usage',
                FieldKind::Choice,
                $unit->usage()->value,
                array_map(
                    static fn (UnitUsage $usage): array => ['value' => $usage->value, 'labelKey' => $usage->labelKey()],
                    UnitUsage::cases(),
                ),
            ),
            new ChangeableField('area', $unit->usage()->areaLabelKey(), FieldKind::Decimal, $unit->measures()->area() ?? ''),
            new ChangeableField('rooms', 'property.unit.field.rooms', FieldKind::Decimal, $unit->measures()->rooms() ?? ''),
            new ChangeableField(
                'parkingSpaces',
                'property.unit.field.parking_spaces',
                FieldKind::Decimal,
                null === $unit->measures()->parkingSpaces() ? '' : (string) $unit->measures()->parkingSpaces(),
            ),
        ];
    }

    public function objectionsTo(string $id, array $values): array
    {
        $unit = $this->units->byId($id);

        if (null === $unit) {
            return ['label' => 'change.error.gone'];
        }

        $objections = [];

        if ('' === self::value($values, 'label', $unit->label())) {
            $objections['label'] = 'change.error.label';
        }

        if (null === self::usageOf($values, $unit)) {
            $objections['usage'] = 'change.error.usage';
        }

        if (!self::measurable($values, $unit)) {
            $objections['area'] = 'change.error.measures';
        }

        return $objections;
    }

    public function apply(string $id, array $values): void
    {
        $unit = $this->units->byId($id);

        if (null === $unit) {
            return;
        }

        $unit->describe(
            self::value($values, 'label', $unit->label()),
            self::usageOf($values, $unit) ?? $unit->usage(),
        );
        $unit->measure(self::measuresOf($values, $unit));

        $this->units->save($unit);
    }

    /**
     * @param array<string, string> $values
     */
    private static function measurable(array $values, Unit $unit): bool
    {
        try {
            self::measuresOf($values, $unit);
        } catch (InvalidArgumentException) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, string> $values
     */
    private static function measuresOf(array $values, Unit $unit): Measures
    {
        $measures = $unit->measures();
        $spaces = self::value($values, 'parkingSpaces', (string) ($measures->parkingSpaces() ?? ''));

        return Measures::of(
            self::orNull(self::value($values, 'area', $measures->area() ?? '')),
            self::orNull(self::value($values, 'rooms', $measures->rooms() ?? '')),
            '' === $spaces ? null : (int) $spaces,
        );
    }

    /**
     * Eine unbekannte Nutzungsart ist kein Fehler des Programms, sondern eine
     * Angabe, die es nicht gibt — sie wird zum Einwand und nicht zur Ausnahme.
     *
     * @param array<string, string> $values
     */
    private static function usageOf(array $values, Unit $unit): ?UnitUsage
    {
        return UnitUsage::tryFrom(self::value($values, 'usage', $unit->usage()->value));
    }

    private static function orNull(string $value): ?string
    {
        return '' === $value ? null : $value;
    }

    /**
     * @param array<string, string> $values
     */
    private static function value(array $values, string $key, string $fallback): string
    {
        return \array_key_exists($key, $values) ? trim($values[$key]) : $fallback;
    }
}
