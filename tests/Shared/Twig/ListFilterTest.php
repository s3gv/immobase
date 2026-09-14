<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Shared\Twig;

use App\Shared\Twig\ListFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Woran eine Liste erkennt, dass jemand gefiltert hat.
 *
 * Daran haengt ein Satz: „noch nichts angelegt" oder „nichts passt zur
 * Suche". Der Unterschied entscheidet, ob der Leser die Daten oder den Filter
 * verdaechtigt.
 */
final class ListFilterTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function addresses(): iterable
    {
        yield 'blanke Liste' => ['/objekte', false];
        yield 'die Seitenzahl filtert nicht' => ['/objekte?page=3', false];
        yield 'ein leeres Feld filtert nicht' => ['/objekte?q=&objekt=', false];
        yield 'nur Leerzeichen filtern nicht' => ['/objekte?q=%20%20', false];
        yield 'eine Suche filtert' => ['/objekte?q=rosenweg', true];
        yield 'eine Auswahl filtert' => ['/objekte?zustand=draft', true];
        yield 'auch neben der Seitenzahl' => ['/objekte?page=2&zustand=draft', true];
        yield 'eine Liste von Werten filtert' => ['/objekte?art%5B%5D=weg', true];
    }

    #[DataProvider('addresses')]
    public function testTellsWhetherSomeoneNarrowedTheList(string $address, bool $filtered): void
    {
        $requests = new RequestStack();
        $requests->push(Request::create($address));

        self::assertSame($filtered, (new ListFilter($requests))->isFiltered());
    }

    /** Ohne Anfrage — etwa aus einem Kommando heraus — ist nichts gefiltert. */
    public function testWithoutARequestNothingIsFiltered(): void
    {
        self::assertFalse((new ListFilter(new RequestStack()))->isFiltered());
    }
}
