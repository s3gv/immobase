<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Billing\Fixture;

use App\Module\Billing\Domain\RentInvoice;
use App\Module\Billing\Domain\RentInvoiceFilter;
use App\Module\Billing\Domain\RentInvoiceRepository;
use App\Shared\Ui\Page;
use RuntimeException;

/**
 * Das echte Repository, dessen zweiter Schreibvorgang scheitert.
 *
 * Fuer die eine Frage, die sich anders nicht stellen laesst: was bleibt
 * stehen, wenn das Ausstellen auf halbem Weg abbricht? Die Ausstellung
 * schliesst erst die Vorgaengerin und friert dann die neue Fassung ein.
 * Bleibt davon die erste Haelfte, ist die Vorgaengerin beendet, ohne dass
 * eine Nachfolgerin gilt.
 *
 * Alles laeuft dabei durch das echte Repository und die echte Datenbank —
 * nur der zweite Aufruf von save() wirft. Ein Speicher-Abbild taete es hier
 * nicht: geprueft wird gerade, dass die Datenbank zurueckrollt.
 */
final class FailsTheSecondWrite implements RentInvoiceRepository
{
    private int $writes = 0;

    public function __construct(private readonly RentInvoiceRepository $real)
    {
    }

    public function save(RentInvoice $invoice): void
    {
        ++$this->writes;

        if (2 === $this->writes) {
            throw new RuntimeException('Der zweite Schreibvorgang scheitert.');
        }

        $this->real->save($invoice);
    }

    public function atomically(callable $work): mixed
    {
        return $this->real->atomically($work);
    }

    public function remove(RentInvoice $invoice): void
    {
        $this->real->remove($invoice);
    }

    public function byId(string $id): ?RentInvoice
    {
        return $this->real->byId($id);
    }

    public function nextNumberFor(string $tenancyId): int
    {
        return $this->real->nextNumberFor($tenancyId);
    }

    public function countMatching(RentInvoiceFilter $filter): int
    {
        return $this->real->countMatching($filter);
    }

    public function matching(RentInvoiceFilter $filter, Page $page): array
    {
        return $this->real->matching($filter, $page);
    }

    public function forTenancy(string $tenancyId): array
    {
        return $this->real->forTenancy($tenancyId);
    }

    public function lastIssuedFor(string $tenancyId): ?RentInvoice
    {
        return $this->real->lastIssuedFor($tenancyId);
    }

    public function correctedAmong(array $ids): array
    {
        return $this->real->correctedAmong($ids);
    }

    public function tenanciesWithAnInvoice(): array
    {
        return $this->real->tenanciesWithAnInvoice();
    }
}
