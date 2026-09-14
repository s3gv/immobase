<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\UserInterface;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

/**
 * Die Fehlerseite entsteht auch dort, wo es noch keine Sitzung gibt.
 *
 * Ein fremder Host wird geprueft, bevor die Sitzung aufgebaut ist. Die Seite
 * dafuer darf nicht selbst an der Sitzung scheitern — der Hintergrund merkt
 * sich seine Lage sonst in ihr, und aus einer 400 wurde eine 500.
 */
final class ErrorPageTest extends KernelTestCase
{
    public function testTheErrorPageRendersWithoutASession(): void
    {
        self::bootKernel();
        $requests = self::getContainer()->get(RequestStack::class);
        self::assertInstanceOf(RequestStack::class, $requests);
        $requests->push(Request::create('/'));

        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        $html = $twig->render('bundles/TwigBundle/Exception/error.html.twig', ['status_code' => 400, 'status_text' => 'Bad Request']);

        self::assertStringContainsString('ib-map', $html);
    }
}
