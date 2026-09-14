<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\UserInterface\Twig;

use App\Module\Property\Domain\Mea;
use App\Shared\Locale\CurrentLocale;
use App\Shared\Number\Decimals;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Schreibt einen Miteigentumsanteil als Bruch — „225,5/1000".
 *
 * Der Zaehler folgt der Sprache, der Nenner nicht: er ist die Skala, auf die
 * sich alles bezieht, und „1.000" laese sich in einem Bruch wie ein Fehler.
 */
final class ShareExtension extends AbstractExtension
{
    public function __construct(private readonly CurrentLocale $locale)
    {
    }

    public function getFilters(): array
    {
        return [new TwigFilter('share', $this->share(...))];
    }

    public function share(Mea $mea): string
    {
        return Decimals::format($mea->numerator(), $this->locale->code(), false).'/'.$mea->denominator;
    }
}
