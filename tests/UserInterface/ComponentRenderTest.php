<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\UserInterface;

use App\Shared\Ui\Page;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Throwable;
use Twig\Environment;

/**
 * Zeichnet jeden Baustein einmal.
 *
 * Bis eben tat das die Musterseite. Sie ist entfallen, und damit gibt es
 * derzeit keinen Aufrufer mehr — die Bausteine warten auf das erste Fachmodul.
 * Ohne diesen Test faende ein Tippfehler in einer Vorlage oder eine umbenannte
 * Variable erst dort jemanden, Monate spaeter und mitten in anderer Arbeit.
 *
 * Geprueft wird bewusst nur, dass sich eine Vorlage mit ihren dokumentierten
 * Variablen zeichnen laesst und etwas dabei herauskommt. Wie sie aussieht,
 * entscheidet kein Test, sondern ein Blick in den Browser.
 */
final class ComponentRenderTest extends KernelTestCase
{
    /**
     * Die im Kopfkommentar jeder Vorlage zugesagten Variablen.
     *
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function components(): iterable
    {
        yield 'icon' => ['components/icon.html.twig', ['name' => 'house']];

        yield 'field' => ['components/field.html.twig', [
            'id' => 'f', 'name' => 'f', 'label' => 'Betreff',
        ]];
        yield 'field, vollständig' => ['components/field.html.twig', [
            'id' => 'f', 'name' => 'f', 'label' => 'Betrag', 'type' => 'text',
            'value' => '12,50', 'hint' => 'Hinweis', 'error' => 'Fehler',
            'required' => true, 'autocomplete' => 'off', 'autofocus' => true,
        ]];

        yield 'stat_tile' => ['components/stat_tile.html.twig', [
            'value' => '128', 'label' => 'Objekte', 'tone' => 'success',
        ]];

        yield 'list_row' => ['components/list_row.html.twig', [
            'title' => 'Aufgabe', 'meta' => 'Objekt 1', 'trailing' => 'Heute', 'tone' => 'danger',
        ]];

        yield 'badge' => ['components/badge.html.twig', ['label' => 'Entwurf', 'tone' => 'warning']];

        yield 'empty_state' => ['components/empty_state.html.twig', ['message' => 'Nichts da.']];

        yield 'table, schlichte Zeilen' => ['components/table.html.twig', [
            'headers' => ['A', 'B'], 'rows' => [['1', '2']], 'empty' => 'leer',
        ]];
        yield 'table, leer' => ['components/table.html.twig', [
            'headers' => ['A'], 'rows' => [], 'empty' => 'leer',
        ]];
        yield 'table, mit Aktionen' => ['components/table.html.twig', [
            'headers' => ['A'],
            'rows' => [['cells' => ['1'], 'actions' => [
                ['action' => 'open', 'url' => '/'],
                ['action' => 'delete', 'modal' => 'x'],
            ]]],
            'empty' => 'leer',
        ]];

        yield 'pagination' => ['components/pagination.html.twig', [
            'page' => 2, 'pages' => 5, 'url' => '/x?p=__PAGE__',
        ]];

        yield 'action, als Knopf' => ['components/action.html.twig', ['action' => 'save']];
        yield 'action, als Link' => ['components/action.html.twig', ['action' => 'open', 'url' => '/']];
        yield 'action, abgeschaltet' => ['components/action.html.twig', ['action' => 'next', 'disabled' => true]];
        yield 'action, beschriftet' => ['components/action.html.twig', ['action' => 'add', 'url' => '/', 'labelled' => true]];

        yield 'select' => ['components/select.html.twig', [
            'id' => 's', 'name' => 's', 'label' => 'Rolle',
            'choices' => [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']],
        ]];
        yield 'select, vollständig' => ['components/select.html.twig', [
            'id' => 's', 'name' => 's', 'label' => 'Rolle',
            'choices' => [['value' => 'a', 'label' => 'A']],
            'value' => 'a', 'blank' => 'Alle', 'hint' => 'Hinweis', 'error' => 'Fehler',
        ]];

        yield 'index_page' => ['components/index_page.html.twig', [
            'headers' => ['A', 'B'],
            'rows' => [['1', '2']],
            'page' => Page::of(2, 120),
            'action' => '/liste',
            'url' => '/liste?page=__PAGE__',
            'empty' => 'Nichts gefunden.',
        ]];
        yield 'index_page, leer' => ['components/index_page.html.twig', [
            'headers' => ['A'],
            'rows' => [],
            'page' => Page::of(1, 0),
            'action' => '/liste',
            'url' => '/liste?page=__PAGE__',
            'empty' => 'Nichts gefunden.',
        ]];

        yield 'breadcrumb' => ['components/breadcrumb.html.twig', [
            'trail' => [['label' => 'ImmoBase', 'url' => '/'], ['label' => 'Hier', 'url' => null]],
        ]];

        yield 'confirm' => ['components/confirm.html.twig', [
            'id' => 'c', 'title' => 'Wirklich?', 'url' => '/', 'token' => 't',
            'body' => 'Erklärung.', 'consequences' => ['Erste Folge.', 'Zweite Folge.'],
        ]];
    }

    /**
     * @param array<string, mixed> $variables
     */
    #[DataProvider('components')]
    public function testRendersWithItsDocumentedVariables(string $template, array $variables): void
    {
        $html = self::twig()->render($template, $variables);

        self::assertNotSame('', trim($html), 'Die Vorlage hat nichts gezeichnet.');
    }

    /**
     * Die Seitennavigation steht mittig unter der Tabelle, die Aktionen
     * oben, die Filter dazwischen — das ist die Zusage des Bausteins.
     */
    public function testTheOverviewKeepsItsOrder(): void
    {
        $html = self::twig()->render('components/index_page.html.twig', [
            'headers' => ['A'],
            'rows' => [['1']],
            'page' => Page::of(2, 120),
            'action' => '/liste',
            'url' => '/liste?page=__PAGE__',
        ]);

        $actions = strpos($html, 'ib-index__actions');
        $filters = strpos($html, 'ib-index__filters');
        $table = strpos($html, 'ib-table');
        $pages = strpos($html, 'ib-index__pages');

        self::assertIsInt($actions);
        self::assertIsInt($filters);
        self::assertIsInt($table);
        self::assertIsInt($pages);
        self::assertTrue($actions < $filters && $filters < $table && $table < $pages);
    }

    /**
     * Beim Filtern beginnt die Liste wieder auf Seite eins. Trüge das
     * Formular die Seitenzahl mit, antwortete eine neue Filterung womoeglich
     * mit einer leeren Seite.
     */
    public function testFilteringStartsOverAtTheFirstPage(): void
    {
        $html = self::twig()->render('components/index_page.html.twig', [
            'headers' => ['A'], 'rows' => [['1']],
            'page' => Page::of(3, 200), 'action' => '/liste', 'url' => '/liste?page=__PAGE__',
        ]);

        self::assertStringContainsString('action="/liste"', $html);
        self::assertStringNotContainsString('name="page"', $html);
    }

    public function testTheEntryCountIsWordedForItsNumber(): void
    {
        $twig = self::twig();
        $render = static fn (int $total): string => $twig->render('components/index_page.html.twig', [
            'headers' => ['A'], 'rows' => [], 'page' => Page::of(1, $total),
            'action' => '/l', 'url' => '/l?page=__PAGE__',
        ]);

        self::assertStringContainsString('Keine Einträge', $render(0));
        self::assertStringContainsString('Ein Eintrag', $render(1));
        self::assertStringContainsString('7 Einträge', $render(7));
    }

    /**
     * Ein Aktionsname ausserhalb des Vokabulars soll auffallen, nicht still
     * einen leeren Knopf hinterlassen.
     */
    /**
     * In der Adresse stehen kodierte Suchbegriffe. Mit sprintf gefuellt hielt
     * `%C3` sich fuer ein Format, und eine Suche nach „ä" endete mit 500.
     */
    public function testThePageLinksKeepAnEncodedSearch(): void
    {
        $html = self::twig()->render('components/pagination.html.twig', [
            'page' => 2, 'pages' => 3, 'url' => '/stammdaten?q=%C3%A4%25d&page=__PAGE__',
        ]);

        self::assertStringContainsString('href="/stammdaten?q=%C3%A4%25d&amp;page=1"', $html);
        self::assertStringContainsString('href="/stammdaten?q=%C3%A4%25d&amp;page=3"', $html);
    }

    public function testRefusesAnActionOutsideTheVocabulary(): void
    {
        $this->expectException(Throwable::class);

        self::twig()->render('components/action.html.twig', ['action' => 'frobnicate']);
    }

    private static function twig(): Environment
    {
        self::bootKernel();

        // Ohne Anfrage mit Sitzung kein csrf_token(), und ohne csrf_token()
        // laesst sich die Rueckfrage nicht zeichnen.
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));

        $requests = self::getContainer()->get(RequestStack::class);
        self::assertInstanceOf(RequestStack::class, $requests);
        $requests->push($request);

        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        return $twig;
    }
}
