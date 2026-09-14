<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Party\Application;

use App\Module\Party\Domain\Addresses;
use App\Module\Party\Domain\ContactDetails;
use App\Module\Party\Domain\Party;
use App\Module\Party\Domain\PartyName;
use App\Module\Party\Domain\PostalAddress;
use App\Module\Party\Domain\TaxId;
use App\Shared\Contact\Email;

/**
 * Ein Vorschlag, auf den Stammdatensatz gelegt.
 *
 * Baut aus den vorgeschlagenen Angaben die Wertobjekte, die beim Uebernehmen
 * wirklich entstehen — und zwar genau dieselben. So ist die Pruefung nicht
 * eine zweite Liste von Regeln neben den Regeln, sondern der Versuch selbst:
 * was sich nicht bauen laesst, laesst sich nicht uebernehmen.
 *
 * Was nicht vorgeschlagen wurde, bleibt, wie es ist. Ein Vorschlag enthaelt
 * nur geaenderte Felder, und ein fehlendes Feld heisst „unveraendert" — nicht
 * „leer".
 */
final readonly class PartyChanges
{
    /**
     * @param array<string, string> $values
     */
    public function __construct(
        private Party $party,
        private array $values,
    ) {
    }

    public function name(): PartyName
    {
        return PartyName::of($this->value('name', $this->party->name()), $this->givenName());
    }

    /**
     * Ein leerer Vorname ist kein Fehler: bei einer Firma heisst das Feld
     * „Ansprechpartner", und den gibt es nicht immer.
     */
    public function givenName(): ?string
    {
        $given = $this->value('givenName', (string) $this->party->givenName());

        return '' === $given ? null : $given;
    }

    /**
     * Die Hauptanschrift neu, die weiteren unveraendert.
     *
     * Auch die Art bleibt: aus einem Postfach wird durch einen Vorschlag
     * keine Hausanschrift — das waere eine andere Angabe und nicht dieselbe
     * in neu.
     */
    public function addresses(): Addresses
    {
        $primary = $this->party->addresses()->primary();

        return Addresses::of([
            PostalAddress::of(
                $primary->kind,
                $this->value('line', $primary->line),
                $this->value('postalCode', $primary->postalCode),
                $this->value('city', $primary->city),
                $this->value('addition', $primary->addition),
            ),
            ...\array_slice($this->party->addresses()->all(), 1),
        ]);
    }

    public function contact(): ContactDetails
    {
        return ContactDetails::of(
            array_map(Email::fromString(...), $this->lines('emails', $this->party->contact()->emails())),
            $this->lines('phones', $this->party->contact()->phones()),
        );
    }

    public function taxId(): TaxId
    {
        $number = $this->value('taxNumber', $this->party->taxId()->toString());

        return '' === $number ? TaxId::none() : TaxId::of($number);
    }

    /**
     * @param list<string> $fallback
     *
     * @return list<string>
     */
    private function lines(string $key, array $fallback): array
    {
        if (!\array_key_exists($key, $this->values)) {
            return $fallback;
        }

        $split = preg_split('/\R/', $this->values[$key]);
        $kept = array_map(trim(...), false === $split ? [] : $split);

        return array_values(array_filter($kept, static fn (string $line): bool => '' !== $line));
    }

    private function value(string $key, string $fallback): string
    {
        return \array_key_exists($key, $this->values) ? trim($this->values[$key]) : $fallback;
    }
}
