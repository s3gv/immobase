<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Application;

use App\Module\Property\Domain\Address;
use App\Module\Property\Domain\Property;
use App\Module\Property\Domain\PropertyRepository;
use App\Shared\Change\ChangeableField;
use App\Shared\Change\FieldKind;
use App\Shared\Change\RecordKind;
use App\Shared\Change\RecordThatMayChange;
use InvalidArgumentException;

/**
 * Was sich an einem Objekt vorschlagen laesst: die Bezeichnung und die
 * Anschrift.
 *
 * Mehr nicht. Verwaltungsart, Wirtschaftsjahr und Bankverbindung sind Sachen
 * der Verwaltung und nicht des Eigentuemers — und ein Vorschlag auf eine
 * Kontonummer waere die Einladung, auf die Betrueger warten.
 *
 * **Jeder Miteigentuemer darf vorschlagen.** Es ist ein Vorschlag; entschieden
 * wird beim Verwalter, und der sieht, von wem er kommt.
 */
final readonly class ChangeableProperty implements RecordThatMayChange
{
    public function __construct(private PropertyRepository $properties)
    {
    }

    public function kind(): RecordKind
    {
        return RecordKind::Property;
    }

    public function fieldsOf(string $id): array
    {
        $property = $this->properties->byId($id);

        if (null === $property) {
            return [];
        }

        $address = $property->address();

        return [
            self::text('name', 'property.field.name', $property->name()),
            self::text('street', 'property.field.street', $address->street()),
            self::text('postalCode', 'property.field.postal_code', $address->postalCode()),
            self::text('city', 'property.field.city', $address->city()),
        ];
    }

    public function objectionsTo(string $id, array $values): array
    {
        $property = $this->properties->byId($id);

        if (null === $property) {
            return ['name' => 'change.error.gone'];
        }

        $objections = [];

        if ('' === self::value($values, 'name', $property->name())) {
            $objections['name'] = 'change.error.name';
        }

        try {
            self::movedTo($property, $values);
        } catch (InvalidArgumentException) {
            $objections['street'] = 'change.error.line';
        }

        return $objections;
    }

    public function apply(string $id, array $values): void
    {
        $property = $this->properties->byId($id);

        if (null === $property) {
            return;
        }

        // Die Verwaltungsart bleibt, wie sie ist: sie steht nicht zur Wahl,
        // und describe() nimmt sie trotzdem entgegen.
        $property->describe(self::value($values, 'name', $property->name()), $property->modes());
        $property->moveTo(self::movedTo($property, $values));

        $this->properties->save($property);
    }

    /**
     * @param array<string, string> $values
     */
    private static function movedTo(Property $property, array $values): Address
    {
        $address = $property->address();

        return Address::of(
            self::value($values, 'street', $address->street()),
            self::value($values, 'postalCode', $address->postalCode()),
            self::value($values, 'city', $address->city()),
        );
    }

    /**
     * @param array<string, string> $values
     */
    private static function value(array $values, string $key, string $fallback): string
    {
        return \array_key_exists($key, $values) ? trim($values[$key]) : $fallback;
    }

    private static function text(string $key, string $labelKey, string $value): ChangeableField
    {
        return new ChangeableField($key, $labelKey, FieldKind::Text, $value);
    }
}
