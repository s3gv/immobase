<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Twig;

use App\Shared\Locale\CurrentLocale;
use App\Shared\Time\Dates;
use DateTimeInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Schreibt Datumsangaben in der Sprache der Anfrage.
 *
 * `{{ tenancy.startsOn|date_local }}` wird zu „31.12.2026" oder „31 Dec 2026".
 * Nicht `date`: so heisst der eingebaute Twig-Filter, der ein Format
 * entgegennimmt und nichts von der Sprache weiss.
 */
final class DateExtension extends AbstractExtension
{
    public function __construct(private readonly CurrentLocale $locale)
    {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('date_local', $this->dateLocal(...)),
            new TwigFilter('datetime_local', $this->dateTimeLocal(...)),
        ];
    }

    public function dateLocal(?DateTimeInterface $date): string
    {
        return null === $date ? '' : Dates::format($date, $this->locale->code());
    }

    public function dateTimeLocal(?DateTimeInterface $date): string
    {
        return null === $date ? '' : Dates::formatWithTime($date, $this->locale->code());
    }
}
