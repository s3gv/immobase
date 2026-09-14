<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\Application;

use App\Module\Portal\Domain\Enquiry;
use App\Module\Portal\Domain\EnquiryFilter;
use App\Module\Portal\Domain\EnquiryRepository;
use App\Shared\Ui\Page;
use Symfony\Component\Clock\ClockInterface;

/**
 * Die Uebersicht der Anfragen — Liste und Kacheln.
 *
 * Drei Zahlen ueber der Liste: was auf uns wartet, was noch niemand gelesen
 * hat, und wie lange es im Schnitt bis zur ersten Antwort dauert. Die dritte
 * ist die einzige, die etwas ueber die Arbeit sagt und nicht ueber den
 * Stapel.
 */
final readonly class SurveyEnquiries
{
    /** Ueber dreissig Tage: ein Schnitt ueber alle Zeiten bewegt sich nie. */
    private const int WINDOW_DAYS = 30;

    public function __construct(
        private EnquiryRepository $enquiries,
        private ClockInterface $clock,
    ) {
    }

    public function count(EnquiryFilter $filter): int
    {
        return $this->enquiries->countMatching($filter);
    }

    /** @return list<Enquiry> */
    public function page(EnquiryFilter $filter, Page $page): array
    {
        return $this->enquiries->matching($filter, $page);
    }

    /**
     * @return array{open: int, unread: int, answeredSeconds: int|null}
     */
    public function tally(): array
    {
        return $this->enquiries->tallyOf($this->clock->now()->modify('-'.self::WINDOW_DAYS.' days'));
    }

    public static function windowDays(): int
    {
        return self::WINDOW_DAYS;
    }
}
