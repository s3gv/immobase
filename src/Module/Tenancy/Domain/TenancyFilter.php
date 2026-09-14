<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Domain;

/**
 * Wonach die Mietliste eingeschraenkt wird.
 *
 * Ohne Zutun zeigt die Liste den laufenden Stand: Entwuerfe und aktive
 * Mietverhaeltnisse. Beendete sind die Geschichte einer Einheit — sie stehen
 * bereit, aber nicht im Weg. Ein ausdruecklich gewaehlter Status schlaegt
 * das: wer „Beendet" waehlt, will Beendetes sehen.
 *
 * Zwei Arten von Einschraenkung, die sich unterscheiden muessen:
 *
 * * `withinUnits` grenzt ein — das ist der Objektfilter, und er gilt
 *   zusaetzlich zu allem anderen.
 * * Die Suche trifft breit: eine Nummer, eine Einheit oder ein Mieter. Was
 *   davon passt, ist eine Oder-Frage.
 *
 * Welche Einheit „Rosenweg" heisst und welcher Kontakt „Muster", weiss dieses
 * Modul nicht — das loesen die Verzeichnisse der anderen Module auf, bevor der
 * Filter entsteht. Hier stehen nur noch Kennungen.
 */
final readonly class TenancyFilter
{
    /**
     * @param list<string>|null $withinUnits     null heisst: kein Objektfilter
     * @param list<string>      $matchingUnits   Einheiten, die zum Suchtext passen
     * @param list<string>      $matchingParties Kontakte, die zum Suchtext passen
     */
    private function __construct(
        public ?TenancyStatus $status,
        public bool $withPast,
        public ?array $withinUnits,
        public bool $searching,
        public ?int $number,
        public array $matchingUnits,
        public array $matchingParties,
    ) {
    }

    public static function none(): self
    {
        return new self(null, false, null, false, null, [], []);
    }

    /**
     * @param list<string>|null $withinUnits
     * @param list<string>      $matchingUnits
     * @param list<string>      $matchingParties
     */
    public static function of(
        ?string $status,
        bool $withPast = false,
        ?array $withinUnits = null,
        ?string $search = null,
        array $matchingUnits = [],
        array $matchingParties = [],
    ): self {
        $term = trim($search ?? '');
        // Ein unbekannter Status aus der Adresszeile schraenkt nicht ein,
        // statt einen Fehler zu erzeugen: er ist Eingabe.
        $chosen = null === $status || '' === trim($status) ? null : TenancyStatus::tryFrom(trim($status));

        return new self(
            $chosen,
            $withPast || true === $chosen?->isPast(),
            $withinUnits,
            '' !== $term,
            ctype_digit($term) ? (int) $term : null,
            $matchingUnits,
            $matchingParties,
        );
    }

    public function isEmpty(): bool
    {
        return null === $this->status
            && !$this->withPast
            && null === $this->withinUnits
            && !$this->searching;
    }
}
