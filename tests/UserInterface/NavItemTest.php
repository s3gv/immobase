<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\UserInterface;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

/**
 * Haelt die Regel, nach der ein Menueeintrag leuchtet.
 *
 * Geprueft wird der Baustein selbst und nicht eine Seite: es gibt derzeit nur
 * eine Seite mit Navigation, und die Regel muss auch fuer die Faelle gelten,
 * die es erst mit den Fachmodulen geben wird — Unterseiten eines Moduls und
 * fremde Routen, deren Name zufaellig aehnlich anfaengt.
 */
final class NavItemTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function routes(): iterable
    {
        yield 'die Route selbst' => ['app_dashboard', true];
        yield 'Unterseite desselben Moduls' => ['app_dashboard_settings', true];
        yield 'tiefere Unterseite' => ['app_dashboard_settings_edit', true];

        yield 'fremde Route' => ['app_flow', false];
        // Ohne den Unterstrich in der Regel wuerde ein blosses starts_with
        // hier faelschlich markieren.
        yield 'Name faengt nur zufaellig gleich an' => ['app_dashboardfoo', false];
        yield 'keine Route' => ['', false];
    }

    #[DataProvider('routes')]
    public function testMarksTheEntryOnlyWhereItBelongs(string $currentRoute, bool $expected): void
    {
        $html = self::render($currentRoute);

        self::assertSame(
            $expected,
            str_contains($html, 'is-current'),
            \sprintf('Bei Route "%s" wurde die Markierung falsch entschieden.', $currentRoute),
        );

        self::assertSame($expected, str_contains($html, 'aria-current="page"'));
    }

    private static function render(string $currentRoute): string
    {
        self::bootKernel();

        $request = new Request();

        if ('' !== $currentRoute) {
            $request->attributes->set('_route', $currentRoute);
        }

        $requests = self::getContainer()->get(RequestStack::class);
        self::assertInstanceOf(RequestStack::class, $requests);
        $requests->push($request);

        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        return $twig->render('components/nav_item.html.twig', [
            'route' => 'app_dashboard',
            'icon' => 'house',
            'label' => 'Übersicht',
        ]);
    }
}
