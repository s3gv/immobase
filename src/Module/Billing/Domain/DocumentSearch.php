<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use App\Shared\Search\SearchTerm;

/**
 * Die Schreiben dieses Moduls fuer die zentrale Suche.
 *
 * Eigene Schnittstelle und nicht fuenf Repository-Methoden: die fuenf Arten
 * unterscheiden sich in ihren Feldern, aber nicht in der Frage. Die Antwort
 * ist eine gemeinsame Liste, und die Reihenfolge darin ist die einzige
 * Stelle, an der entschieden wird, was oben steht.
 */
interface DocumentSearch
{
    /**
     * @return list<DocumentHit> das juengste Jahr zuerst
     */
    public function anywhere(SearchTerm $term, int $limit): array;
}
