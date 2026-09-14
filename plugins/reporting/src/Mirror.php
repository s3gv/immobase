<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace Reporting;

use Closure;
use DateTimeImmutable;

/**
 * Holt den Bestand aus dem Core in den eigenen Spiegel.
 *
 * **Vollstaendig und nicht stueckweise.** Eine Auswertung, die auf einem
 * halben Spiegel rechnet, geht nicht auf — und „geht nicht auf" ist bei
 * Zahlen schlimmer als „ist von gestern". Der Bestand einer Hausverwaltung
 * ist klein genug dafuer; bei einem grossen waere der naechste Schritt, nur
 * das Geaenderte zu holen.
 *
 * **Erst alles holen, dann umschalten.** Alle Abfragen an den Core laufen,
 * bevor eine Tabelle angefasst wird. Scheitert eine davon, bleibt der alte
 * Stand vollstaendig stehen.
 *
 * **Holen und Schreiben unter einer Sperre.** Zwei Spiegelungen zugleich —
 * ein Webhook und ein Seitenaufruf — duerfen nicht beide holen und dann
 * nacheinander schreiben: die langsamere, aeltere ueberschriebe die neuere
 * und gaelte danach noch eine Viertelstunde als frisch. Die zweite wartet
 * deshalb, bis die erste fertig ist, und schaut dann nach, ob deren Stand
 * schon enthaelt, weswegen sie gekommen ist. Wenn ja, bleibt es dabei.
 *
 * Angestossen wird sie von zwei Seiten: von einem Webhook, wenn sich etwas
 * geaendert hat, und von einem Seitenaufruf, wenn der Spiegel alt ist. Ein
 * eigener Dauerlauf braucht es dafuer nicht.
 */
final readonly class Mirror
{
    /** Juenger als das gilt als frisch genug. */
    public const string FRESH = '-15 minutes';

    /**
     * So lange nach einer Aenderung gilt ein Abruf noch nicht als sicher danach.
     *
     * Der Zeitpunkt im Webhook ist der des Schreibens, nicht der des
     * Festschreibens, und er kommt auf die Sekunde gerundet. Ein Abruf, der
     * wenige Augenblicke danach begann, kann die Aenderung noch nicht gesehen
     * haben.
     */
    private const string SETTLING = '+3 seconds';

    /**
     * @param Closure(): DateTimeImmutable $now
     */
    public function __construct(
        private Source $core,
        private MirrorStorage $store,
        private Closure $now,
    ) {
    }

    /**
     * Spiegeln — es sei denn, der Spiegel wurde nach `$since` schon geholt.
     *
     * Fuer einen Webhook ist `$since` der Zeitpunkt der Aenderung, fuer einen
     * Seitenaufruf der Anfang der Frische. Gemerkt wird, wann der Abruf
     * **begann**: was danach geschrieben wurde, kann er nicht enthalten.
     *
     * @return bool ob gespiegelt wurde
     */
    public function refreshUnlessCovered(DateTimeImmutable $since): bool
    {
        return $this->store->exclusively(function () use ($since): bool {
            $last = $this->store->lastSync('full');

            if (null !== $last && $last >= $since->modify(self::SETTLING)) {
                return false;
            }

            $startedAt = ($this->now)();
            $this->store->replaceAll($this->snapshot(), $startedAt);

            return true;
        });
    }

    /**
     * @return array<string, list<array<string, scalar|null>>>
     */
    private function snapshot(): array
    {
        return [
            'property' => $this->properties(),
            'unit' => $this->units(),
            'tenancy' => $this->tenancies(),
            'cost_year' => $this->costs(),
            'claim' => $this->claims(),
            'reserve' => $this->reserves(),
            'loan' => $this->loans(),
        ];
    }

    /**
     * @return list<array<string, scalar|null>>
     */
    private function properties(): array
    {
        $rows = [];

        foreach ($this->core->everything('properties') as $record) {
            $address = $record['address'] ?? [];

            $rows[] = [
                'id' => self::text($record, 'id'),
                'number' => self::number($record, 'number'),
                'name' => self::text($record, 'name'),
                'city' => \is_array($address) ? self::text($address, 'city') : '',
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, scalar|null>>
     */
    private function units(): array
    {
        $rows = [];

        foreach ($this->core->everything('units') as $record) {
            $rows[] = [
                'id' => self::text($record, 'id'),
                'property_id' => self::text($record, 'property_id'),
                'label' => self::text($record, 'label'),
                'area' => self::decimalOrNull($record, 'area'),
                'status' => self::text($record, 'status'),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, scalar|null>>
     */
    private function tenancies(): array
    {
        $rows = [];

        foreach ($this->core->everything('tenancies') as $record) {
            $rows[] = [
                'id' => self::text($record, 'id'),
                'unit_id' => self::text($record, 'unit_id'),
                'status' => self::text($record, 'status'),
                'starts_on' => self::dayOrNull($record, 'starts_on'),
                'ends_on' => self::dayOrNull($record, 'ends_on'),
            ];
        }

        return $rows;
    }

    /**
     * Kosten je Wirtschaftsjahr — eine Zeile je Jahr, nicht je Position.
     *
     * Die Jahre kommen im Datensatz mit; auseinandergenommen werden sie hier,
     * weil eine Auswertung nach Jahren fragt und nicht nach Positionen.
     *
     * @return list<array<string, scalar|null>>
     */
    private function costs(): array
    {
        $rows = [];

        foreach ($this->core->everything('costs') as $record) {
            $years = $record['years'] ?? [];

            foreach (\is_array($years) ? $years : [] as $year) {
                if (\is_array($year)) {
                    $rows[] = self::costYear($record, $year);
                }
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $year
     *
     * @return array<string, scalar|null>
     */
    private static function costYear(array $record, array $year): array
    {
        $fiscalYear = self::number($year, 'fiscal_year');

        return [
            'id' => self::text($record, 'id').':'.$fiscalYear,
            'cost_id' => self::text($record, 'id'),
            'property_id' => self::text($record, 'property_id'),
            'cost_kind' => self::text($record, 'cost_kind'),
            'apportionable' => (bool) ($record['apportionable'] ?? false),
            'fiscal_year' => $fiscalYear,
            'amount' => self::money($year, 'amount'),
        ];
    }

    /**
     * @return list<array<string, scalar|null>>
     */
    private function claims(): array
    {
        $rows = [];

        foreach ($this->core->everything('claims') as $record) {
            $steps = $record['steps'] ?? [];

            $rows[] = [
                'id' => self::text($record, 'id'),
                'property_id' => self::text($record, 'property_id'),
                'party_id' => self::text($record, 'party_id'),
                'subject' => self::text($record, 'subject'),
                'due_on' => self::dayOrNull($record, 'due_on'),
                'settled_on' => self::dayOrNull($record, 'settled_on'),
                'open_amount' => self::money($record, 'open'),
                'steps' => \is_array($steps) ? \count($steps) : 0,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, scalar|null>>
     */
    private function reserves(): array
    {
        $rows = [];

        foreach ($this->core->everything('reserve-movements') as $record) {
            $rows[] = [
                'id' => self::text($record, 'id'),
                'property_id' => self::text($record, 'property_id'),
                'occurred_on' => self::dayOrNull($record, 'occurred_on') ?? '1970-01-01',
                'effect' => self::money($record, 'effect'),
                'reversed' => (bool) ($record['reversed'] ?? false),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, scalar|null>>
     */
    private function loans(): array
    {
        $rows = [];

        foreach ($this->core->everything('loans') as $record) {
            $rows[] = [
                'id' => self::text($record, 'id'),
                'property_id' => self::text($record, 'property_id'),
                'label' => self::text($record, 'label'),
                'lender' => self::text($record, 'lender'),
                'amount' => self::money($record, 'amount'),
                'rate_bps' => self::number($record, 'rate_bps'),
                'starts_on' => self::dayOrNull($record, 'starts_on'),
            ];
        }

        return $rows;
    }

    /**
     * Geld kommt als Dezimalzeichenkette und bleibt eine.
     *
     * @param array<string, mixed> $record
     */
    private static function money(array $record, string $key): string
    {
        $value = $record[$key] ?? null;
        $amount = \is_array($value) ? $value['amount'] ?? '0' : '0';

        return \is_string($amount) ? $amount : '0';
    }

    /**
     * @param array<string, mixed> $record
     */
    private static function text(array $record, string $key): string
    {
        $value = $record[$key] ?? '';

        return \is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param array<string, mixed> $record
     */
    private static function number(array $record, string $key): int
    {
        $value = $record[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param array<string, mixed> $record
     */
    private static function decimalOrNull(array $record, string $key): ?string
    {
        $value = $record[$key] ?? null;

        return \is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * @param array<string, mixed> $record
     */
    private static function dayOrNull(array $record, string $key): ?string
    {
        $value = $record[$key] ?? null;

        return \is_string($value) && '' !== $value ? substr($value, 0, 10) : null;
    }
}
