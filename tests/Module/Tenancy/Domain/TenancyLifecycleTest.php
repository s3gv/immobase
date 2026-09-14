<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Tenancy\Domain;

use App\Module\Tenancy\Domain\Tenancy;
use App\Module\Tenancy\Domain\TenancyNeedsAnEnd;
use App\Module\Tenancy\Domain\TenancyNeedsATenant;
use App\Module\Tenancy\Domain\TenancyStatus;
use App\Module\Tenancy\Domain\Tenant;
use App\Module\Tenancy\Domain\Term;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Drei Zustaende, und der Weg dazwischen.
 *
 * „Inaktiv" musste vorher fuer zweierlei herhalten — den Entwurf und die
 * Geschichte. Die Tests halten fest, dass beide jetzt Verschiedenes sind.
 */
final class TenancyLifecycleTest extends TestCase
{
    public function testItStartsAsADraft(): void
    {
        self::assertSame(TenancyStatus::Draft, self::tenancy()->status());
    }

    public function testItCannotBeActivatedWithoutATenant(): void
    {
        $this->expectException(TenancyNeedsATenant::class);

        self::tenancy()->activate();
    }

    public function testEndingWithoutADateIsRefused(): void
    {
        $tenancy = self::active();

        $this->expectException(TenancyNeedsAnEnd::class);

        // Ohne den Tag traegt es einen Zeitraum ohne Ende — und ist fuer
        // jede tagesgenaue Abrechnung wertlos.
        $tenancy->endOn(null);
    }

    public function testEndingRecordsTheDay(): void
    {
        $tenancy = self::active();

        $tenancy->endOn(new DateTimeImmutable('2026-06-30'));

        self::assertSame(TenancyStatus::Ended, $tenancy->status());
        self::assertSame('2026-06-30', $tenancy->term()->endsOn()?->format('Y-m-d'));
    }

    public function testItCannotEndBeforeItBegan(): void
    {
        $tenancy = self::active();

        $this->expectException(InvalidArgumentException::class);

        $tenancy->endOn(new DateTimeImmutable('2025-12-31'));
    }

    /**
     * Das Mietende bleibt beim Zuruecknehmen stehen.
     *
     * Bei einem Zeitmietvertrag stand es schon vorher da; welches der beiden
     * gemeint war, weiss die Entity nicht — und still das falsche zu
     * loeschen waere schlimmer als ein Hinweis.
     */
    public function testReopeningKeepsTheRecordedEnd(): void
    {
        $tenancy = self::active();
        $tenancy->endOn(new DateTimeImmutable('2026-06-30'));

        $tenancy->activate();

        self::assertSame(TenancyStatus::Active, $tenancy->status());
        self::assertSame('2026-06-30', $tenancy->term()->endsOn()?->format('Y-m-d'));
    }

    private static function tenancy(): Tenancy
    {
        $tenancy = new Tenancy(30001, 'einheit-1');
        $tenancy->runFor(Term::of(new DateTimeImmutable('2026-01-01'), null, null, null));

        return $tenancy;
    }

    private static function active(): Tenancy
    {
        $tenancy = self::tenancy();
        new Tenant($tenancy, 'kontakt-1');
        $tenancy->activate();

        return $tenancy;
    }
}
