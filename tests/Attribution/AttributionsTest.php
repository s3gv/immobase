<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Attribution;

use PHPUnit\Framework\TestCase;

/**
 * Haelt NOTICE und die erzeugte Attributionsliste in Deckung.
 *
 * Es gibt zwei Listen, weil sie zwei Leser haben: NOTICE ist die Datei, in
 * die ein Jurist schaut, assets/attributions.json ist das, was Nutzer im
 * Programm sehen. Zwei Listen laufen erfahrungsgemaess auseinander — dieser
 * Test prueft beide Richtungen.
 *
 * Eine fehlende Namensnennung ist kein Schoenheitsfehler: die Lizenzen
 * verlangen sie.
 */
final class AttributionsTest extends TestCase
{
    /**
     * Hosts, die in NOTICE nur als Lizenztext verlinkt sind und deshalb
     * keine eigene Namensnennung brauchen.
     */
    private const LICENSE_HOSTS = ['www.gnu.org', 'creativecommons.org'];

    public function testTheGeneratedListIsPresentAndReadable(): void
    {
        self::assertNotSame([], self::manualEntries());
        self::assertNotSame([], self::packages(), 'Ohne Pakete stimmt etwas mit dem Erzeuger nicht.');
    }

    public function testEveryAttributedSourceIsNamedInNotice(): void
    {
        $notice = self::notice();

        foreach (self::manualEntries() as $entry) {
            foreach ($entry['links'] as $link) {
                self::assertStringContainsString(
                    self::hostOf($link['url']),
                    $notice,
                    \sprintf('%s steht in der Attributionsliste, aber nicht in NOTICE.', $link['url']),
                );
            }
        }
    }

    public function testEverySourceInNoticeIsAlsoAttributed(): void
    {
        $attributed = json_encode(self::manualEntries(), \JSON_THROW_ON_ERROR);

        foreach (self::hostsInNotice() as $host) {
            self::assertStringContainsString(
                $host,
                $attributed,
                \sprintf('%s wird in NOTICE genannt, fehlt aber in der Attributionsliste.', $host),
            );
        }
    }

    /**
     * Die Datenlizenz Deutschland schreibt Wortlaut und Verlinkung vor.
     */
    public function testTheGeodataAttributionCarriesTheRequiredWording(): void
    {
        $entries = array_values(array_filter(
            self::manualEntries(),
            static fn (array $entry): bool => str_contains($entry['name'], 'BKG'),
        ));

        self::assertCount(1, $entries);
        self::assertStringContainsString('© BKG (2026) dl-de/by-2-0', $entries[0]['note']);
        self::assertStringContainsString('Datenquellen:', $entries[0]['note']);
        self::assertNotSame('', $entries[0]['modification'], 'Die Änderung an den Daten muss benannt sein.');

        $urls = array_map(static fn (array $link): string => $link['url'], $entries[0]['links']);

        self::assertContains('https://www.bkg.bund.de', $urls);
        self::assertContains('https://www.govdata.de/dl-de/by-2-0', $urls);
    }

    /**
     * @return list<string>
     */
    private static function hostsInNotice(): array
    {
        preg_match_all('#https?://([A-Za-z0-9.-]+)#', self::notice(), $matches);

        $sources = array_diff(array_unique($matches[1]), self::LICENSE_HOSTS);

        self::assertNotSame([], $sources);

        return array_values($sources);
    }

    private static function hostOf(string $url): string
    {
        $host = parse_url($url, \PHP_URL_HOST);

        self::assertIsString($host, \sprintf('"%s" ist keine brauchbare Adresse.', $url));

        return $host;
    }

    private static function notice(): string
    {
        return self::read('NOTICE');
    }

    /**
     * Liest die festen Eintraege und bringt sie in eine geprueft feste Form.
     *
     * Aus JSON kommt alles als mixed. Statt an jeder Verwendungsstelle zu
     * pruefen, wird einmal hier geprueft — dann arbeiten die Tests mit
     * gesicherten Typen.
     *
     * @return list<array{name: string, license: string, note: string, modification: string, links: list<array{label: string, url: string}>}>
     */
    private static function manualEntries(): array
    {
        $entries = [];

        foreach (self::section('manual') as $raw) {
            self::assertIsArray($raw);

            $links = $raw['links'] ?? null;
            self::assertIsArray($links);

            $entries[] = [
                'name' => self::text($raw, 'name'),
                'license' => self::text($raw, 'license'),
                'note' => self::text($raw, 'note'),
                'modification' => \is_string($raw['modification'] ?? null) ? $raw['modification'] : '',
                'links' => self::links($links),
            ];
        }

        return $entries;
    }

    /**
     * @param array<mixed> $raw
     *
     * @return list<array{label: string, url: string}>
     */
    private static function links(array $raw): array
    {
        $links = [];

        foreach ($raw as $link) {
            self::assertIsArray($link);

            $links[] = ['label' => self::text($link, 'label'), 'url' => self::text($link, 'url')];
        }

        return $links;
    }

    /**
     * @param array<mixed> $entry
     */
    private static function text(array $entry, string $key): string
    {
        $value = $entry[$key] ?? null;

        self::assertIsString($value, \sprintf('"%s" fehlt oder ist kein Text.', $key));

        return $value;
    }

    /**
     * @return list<mixed>
     */
    private static function packages(): array
    {
        return self::section('packages');
    }

    /**
     * @return list<mixed>
     */
    private static function section(string $name): array
    {
        $document = json_decode(self::read('assets/attributions.json'), true, 512, \JSON_THROW_ON_ERROR);

        self::assertIsArray($document);

        $section = $document[$name] ?? null;

        self::assertIsList($section, \sprintf('"%s" fehlt in der Attributionsliste.', $name));

        return $section;
    }

    private static function read(string $path): string
    {
        $contents = file_get_contents(\dirname(__DIR__, 2).'/'.$path);

        self::assertIsString($contents, \sprintf('%s fehlt. Erzeugen mit: make attributions', $path));

        return $contents;
    }
}
