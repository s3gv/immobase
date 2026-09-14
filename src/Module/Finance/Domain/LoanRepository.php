<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use App\Shared\Ui\Page;
use App\Shared\Ui\Sort;

/**
 * Die Darlehen — gespeichert und wiedergefunden.
 */
interface LoanRepository
{
    public function save(Loan $loan): void;

    public function remove(Loan $loan): void;

    public function byId(string $id): ?Loan;

    public function byNumber(int $number): ?Loan;

    /**
     * Das Darlehen zu einer Beschlussreferenz — null, wenn es keines gibt.
     *
     * Damit ein zweiter Beschluss derselben Massnahme kein zweites Darlehen
     * anlegt. Die Referenz ist ueber alle Fassungen hinweg dieselbe.
     */
    public function byReference(string $reference): ?Loan;

    /** Die naechste sichtbare Nummer, ab 50001. */
    public function nextNumber(): int;

    public function countMatching(LoanFilter $filter): int;

    /**
     * Die Darlehen einer Seite, so sortiert wie gewuenscht.
     *
     * @return list<Loan>
     */
    public function matching(LoanFilter $filter, Page $page, Sort $sort): array;

    /**
     * Alle Darlehen, die juengsten zuerst.
     *
     * @return list<Loan>
     */
    public function all(): array;

    /**
     * Die Darlehen eines Objekts.
     *
     * @return list<Loan>
     */
    public function forProperty(string $propertyId): array;
}
