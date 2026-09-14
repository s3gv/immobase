<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Party\Application;

use App\Module\Party\Domain\Party;
use App\Module\Party\Domain\PartyKind;
use App\Module\Party\Domain\PartyRepository;
use App\Shared\Change\ChangeableField;
use App\Shared\Change\FieldKind;
use App\Shared\Change\RecordKind;
use App\Shared\Change\RecordThatMayChange;
use InvalidArgumentException;

/**
 * Was sich an einem Stammdatensatz vorschlagen laesst.
 *
 * **Die Hauptanschrift und keine zweite.** Wer eine weitere Anschrift
 * braucht, hat etwas anderes vor als „wir sind umgezogen" — und eine Liste,
 * in der sich Zeilen hinzufuegen und entfernen lassen, ist kein Vorschlag
 * mehr, sondern ein zweites Formular fuer die Stammdaten.
 *
 * **Die Steuernummer nur beim Eigentuemer.** Sie steht auf seinen
 * Dauermietrechnungen; bei allen anderen waere das Feld eine Frage, auf die
 * niemand eine Antwort hat.
 *
 * Rolle, Art und Notiz stehen nicht zur Wahl: ob jemand Mieter oder
 * Eigentuemer ist, entscheidet ein Vertrag und kein Vorschlag, und die Notiz
 * ist der Platz der Verwaltung fuer ihre eigenen Vermerke.
 */
final readonly class ChangeableParty implements RecordThatMayChange
{
    public function __construct(private PartyRepository $parties)
    {
    }

    public function kind(): RecordKind
    {
        return RecordKind::Party;
    }

    public function fieldsOf(string $id): array
    {
        $party = $this->parties->byId($id);

        if (null === $party) {
            return [];
        }

        $fields = [...self::naming($party), ...self::living($party), ...self::reaching($party)];

        if ($party->roles()->includesOwner()) {
            $fields[] = self::text('taxNumber', 'party.field.tax_number', $party->taxId()->toString());
        }

        return $fields;
    }

    /**
     * Geprueft wird, indem die Wertobjekte gebaut werden, die beim Uebernehmen
     * wirklich entstehen. Eine eigene Pruefliste waere die zweite Stelle, an
     * der steht, was eine Anschrift ausmacht — und sie liefe irgendwann
     * anders als die erste.
     */
    public function objectionsTo(string $id, array $values): array
    {
        $party = $this->parties->byId($id);

        if (null === $party) {
            return ['name' => 'change.error.gone'];
        }

        $changes = new PartyChanges($party, $values);
        $objections = [];

        foreach ([
            'name' => $changes->name(...),
            'line' => $changes->addresses(...),
            'emails' => $changes->contact(...),
            'taxNumber' => $changes->taxId(...),
        ] as $field => $build) {
            if (!self::holds($build)) {
                $objections[$field] = 'change.error.'.$field;
            }
        }

        return $objections;
    }

    public function apply(string $id, array $values): void
    {
        $party = $this->parties->byId($id);

        if (null === $party) {
            return;
        }

        $changes = new PartyChanges($party, $values);

        $party->rename($changes->name()->name(), $changes->givenName());
        $party->moveTo($changes->addresses());
        $party->reachAt($changes->contact());
        $party->taxedAs($changes->taxId());

        $this->parties->save($party);
    }

    /**
     * @return list<ChangeableField>
     */
    private static function naming(Party $party): array
    {
        $company = PartyKind::Company === $party->kind();

        return [
            self::text('name', $company ? 'party.field.company_name' : 'party.field.last_name', $party->name()),
            self::text(
                'givenName',
                $company ? 'party.field.contact_person' : 'party.field.first_name',
                $party->givenName() ?? '',
            ),
        ];
    }

    /**
     * @return list<ChangeableField>
     */
    private static function living(Party $party): array
    {
        $address = $party->addresses()->primary();

        return [
            self::text('addition', 'party.field.addition', $address->addition),
            self::text('line', $address->isPoBox() ? 'party.field.po_box' : 'party.field.street', $address->line),
            self::text('postalCode', 'party.field.postal_code', $address->postalCode),
            self::text('city', 'party.field.city', $address->city),
        ];
    }

    /**
     * @return list<ChangeableField>
     */
    private static function reaching(Party $party): array
    {
        return [
            new ChangeableField(
                'emails',
                'party.field.emails',
                FieldKind::Lines,
                implode("\n", $party->contact()->emails()),
            ),
            new ChangeableField(
                'phones',
                'party.field.phones',
                FieldKind::Lines,
                implode("\n", $party->contact()->phones()),
            ),
        ];
    }

    /**
     * @param callable(): mixed $build
     */
    private static function holds(callable $build): bool
    {
        try {
            $build();
        } catch (InvalidArgumentException) {
            return false;
        }

        return true;
    }

    private static function text(string $key, string $labelKey, string $value): ChangeableField
    {
        return new ChangeableField($key, $labelKey, FieldKind::Text, $value);
    }
}
