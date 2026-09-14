<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Plugin\Domain;

use App\Module\Plugin\Domain\Manifest\ColumnType;
use App\Module\Plugin\Domain\Manifest\ManifestFault;
use App\Module\Plugin\Domain\Manifest\ManifestReader;
use App\Module\Plugin\Domain\Manifest\TableReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Das Manifest ist eine fremde Datei.
 *
 * Was hier durchkommt, geht in den Rechtekatalog, in DDL und in Adressen, die
 * der Core selbst aufruft. Die Zusicherung ist deshalb nicht „es liest ein
 * Manifest", sondern **es nimmt nur an, was es prüfen konnte** — und sagt bei
 * jeder Ablehnung, an welcher Stelle es hakt.
 */
final class ManifestReaderTest extends TestCase
{
    /** Ein vollständiges Manifest kommt vollständig an. */
    public function testItReadsWhatAnPluginSaysAboutItself(): void
    {
        $manifest = self::read(self::complete());

        self::assertSame('reporting', $manifest->name);
        self::assertSame('1.0.0', $manifest->version);
        self::assertSame('Auswertungen', $manifest->label('de'));
        self::assertSame(['reporting.view'], $manifest->permissionKeys());
        self::assertSame(['finance.view'], $manifest->reads);
        self::assertSame(['cost.updated'], $manifest->events);
        self::assertSame('/webhook', $manifest->webhookPath);
        self::assertFalse($manifest->internet, 'Ohne Angabe kein Internet');

        self::assertCount(1, $manifest->nav);
        self::assertSame('/kosten', $manifest->nav[0]->path);
        self::assertSame('reporting.view', $manifest->nav[0]->permission);

        self::assertCount(1, $manifest->tables);
        self::assertSame('cost_mirror', $manifest->tables[0]->name);
        self::assertCount(2, $manifest->tables[0]->columns);
        self::assertSame(ColumnType::Uuid, $manifest->tables[0]->columns[0]->type);
        self::assertTrue($manifest->tables[0]->columns[0]->primary);
    }

    /**
     * Ins Internet nur mit ausdruecklicher Angabe — und wer sie dazunimmt,
     * braucht eine neue Zustimmung, wie fuer ein neues Recht.
     */
    public function testTheInternetIsAskedForAndNeedsAgreementAgain(): void
    {
        $without = self::read(self::complete());
        $with = self::read(self::complete(['internet' => true]));

        self::assertTrue($with->internet);
        self::assertTrue($with->wantsMoreThan($without));
        self::assertFalse($without->wantsMoreThan($with));
    }

    /** Fehlt eine Sprache, steht da nicht der Schlüssel, sondern die andere. */
    public function testALabelFallsBackInsteadOfDisappearing(): void
    {
        self::assertSame('Reports', self::read(self::complete())->label('fr'));
    }

    /**
     * Jede Ablehnung nennt die Stelle.
     *
     * Wer sein erstes Plugin baut, soll den Fehler finden, ohne unseren
     * Quelltext zu lesen — „ungültiges Manifest" leistet das nicht.
     *
     * @param array<string, mixed> $manifest
     */
    #[DataProvider('brokenManifests')]
    public function testItRefusesWhatItCannotCheck(array $manifest, string $where): void
    {
        $this->expectException(ManifestFault::class);
        $this->expectExceptionMessageMatches('/'.preg_quote($where, '/').'/');

        self::read($manifest);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function brokenManifests(): iterable
    {
        // Ein künftiger Stand darf Felder anders meinen. Ein alter Leser
        // versteht sie dann falsch — also liest er sie gar nicht erst.
        yield 'unbekannter Stand' => [self::complete(['api' => 2]), 'api'];

        // Der Name trägt das Datenbankschema, die Adresse und die Rechte. Ein
        // Manifest, das anders heißt als sein Verzeichnis, benennt ein
        // anderes Plugin.
        yield 'Name passt nicht zum Verzeichnis' => [self::complete(['name' => 'andere']), 'name'];
        yield 'Name mit Bindestrich' => [self::complete(['name' => 'aus-wertung']), 'name'];

        // Aus diesen Namen entsteht DDL.
        yield 'Tabellenname mit Anführungszeichen' => [
            self::withTable(['name' => 'x"; DROP TABLE users; --', 'columns' => [['name' => 'id', 'type' => 'uuid']]]),
            'name',
        ];
        yield 'unbekannter Spaltentyp' => [
            self::withTable(['name' => 'x', 'columns' => [['name' => 'id', 'type' => 'jsonb']]]),
            'type',
        ];
        yield 'Tabelle ohne Spalten' => [self::withTable(['name' => 'x', 'columns' => []]), 'columns'];

        // Ein Pfad, der aus dem Plugin herausführt, führt irgendwohin.
        yield 'Pfad nach oben' => [self::withNav(['path' => '/../../etc']), 'path'];
        yield 'Pfad auf fremden Rechner' => [self::withNav(['path' => '//example.org/x']), 'path'];

        // Ein Menüpunkt hinter einem fremden Recht wäre ein Plugin, das sich
        // hinter der Berechtigung eines Moduls versteckt.
        yield 'fremdes Recht am Menüpunkt' => [self::withNav(['permission' => 'finance.view']), 'permission'];

        // Das Symbol wird aus dem Verzeichnis eingebunden.
        yield 'erfundenes Symbol' => [self::withNav(['icon' => '../../secret']), 'icon'];

        // In der Rechtematrix stünde sonst die Beschriftung des Plugins bei
        // „Benutzer verwalten".
        yield 'Recht im Bereich eines Moduls' => [
            self::complete(['permissions' => [['area' => 'users', 'actions' => ['edit'], 'label' => ['de' => 'Berichte']]]]),
            'area',
        ];

        yield 'Internet nicht als ja oder nein' => [self::complete(['internet' => 'ja']), 'internet'];

        yield 'Lesebereich ist kein Schlüssel' => [self::complete(['reads' => ['alles']]), 'reads'];
        yield 'unbekannte Aktion' => [
            self::complete(['permissions' => [['area' => 'reporting', 'actions' => ['approve'], 'label' => ['de' => 'x']]]]),
            'actions',
        ];
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private static function read(array $manifest): \App\Module\Plugin\Domain\Manifest\Manifest
    {
        $json = json_encode($manifest);
        self::assertIsString($json);

        return (new ManifestReader(new TableReader()))->read('reporting', $json);
    }

    /**
     * Ein vollständiges Manifest, auf Wunsch an einer Stelle verbogen.
     *
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private static function complete(array $changes = []): array
    {
        return [...[
            'api' => 1,
            'name' => 'reporting',
            'version' => '1.0.0',
            'label' => ['de' => 'Auswertungen', 'en' => 'Reports'],
            'permissions' => [['area' => 'reporting', 'actions' => ['view'], 'label' => ['de' => 'Auswertungen', 'en' => 'Reports']]],
            'reads' => ['finance.view'],
            'nav' => [['path' => '/kosten', 'label' => ['de' => 'Kosten', 'en' => 'Costs'], 'permission' => 'reporting.view']],
            'tables' => [['name' => 'cost_mirror', 'columns' => [
                ['name' => 'id', 'type' => 'uuid', 'primary' => true],
                ['name' => 'amount', 'type' => 'decimal'],
            ]]],
            'events' => ['cost.updated'],
        ], ...$changes];
    }

    /**
     * @param array<string, mixed> $table
     *
     * @return array<string, mixed>
     */
    private static function withTable(array $table): array
    {
        return self::complete(['tables' => [$table]]);
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private static function withNav(array $changes): array
    {
        $entry = ['path' => '/kosten', 'label' => ['de' => 'Kosten', 'en' => 'Costs'], 'permission' => 'reporting.view'];

        return self::complete(['nav' => [[...$entry, ...$changes]]]);
    }
}
