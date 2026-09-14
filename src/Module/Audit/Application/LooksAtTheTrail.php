<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Audit\Application;

use App\Module\Audit\Contract\AuditLine;
use App\Module\Audit\Domain\AuditEntry;
use App\Module\Audit\Domain\AuditFilter;
use App\Module\Audit\Domain\AuditRepository;
use App\Shared\Audit\LinksToRecords;
use App\Shared\Ui\Page;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Das Protokoll, wie es auf der Seite steht.
 *
 * Die Wege zu den Datensaetzen kommen aus den Modulen, die sie besitzen —
 * gefragt wird je Art einmal fuer die ganze Seite. Einzeln gefragt waeren es
 * fuenfzig Abfragen, und die Liste waere langsam an genau der Stelle, an der
 * jemand nachsieht, weil etwas schiefgegangen ist.
 */
final readonly class LooksAtTheTrail
{
    /**
     * @param iterable<LinksToRecords> $modules
     */
    public function __construct(
        private AuditRepository $entries,
        #[AutowireIterator('audit.links')]
        private iterable $modules,
    ) {
    }

    public function count(AuditFilter $filter): int
    {
        return $this->entries->countMatching($filter);
    }

    /**
     * @return list<AuditLine>
     */
    public function page(AuditFilter $filter, Page $page): array
    {
        return $this->linked($this->entries->matching($filter, $page));
    }

    /**
     * @return list<AuditLine>
     */
    public function everything(): array
    {
        return $this->linked($this->entries->all());
    }

    /**
     * @param list<AuditEntry> $entries
     *
     * @return list<AuditLine>
     */
    private function linked(array $entries): array
    {
        $urls = $this->urlsFor($entries);

        return array_map(
            static fn (AuditEntry $entry): AuditLine => new AuditLine(
                $entry,
                $urls[$entry->record().'|'.$entry->recordId()] ?? '',
            ),
            $entries,
        );
    }

    /**
     * Die Adressen aller Datensaetze dieser Seite, je Art in einem Zug.
     *
     * @param list<AuditEntry> $entries
     *
     * @return array<string, string> „Art|Kennung" auf Adresse
     */
    private function urlsFor(array $entries): array
    {
        $wanted = [];

        foreach ($entries as $entry) {
            if ('' !== $entry->record() && '' !== $entry->recordId()) {
                $wanted[$entry->record()][$entry->recordId()] = true;
            }
        }

        $urls = [];

        foreach ($this->modules as $module) {
            foreach ($module->handles() as $record) {
                foreach ($module->urlsFor($record, array_keys($wanted[$record] ?? [])) as $id => $url) {
                    $urls[$record.'|'.$id] = $url;
                }
            }
        }

        return $urls;
    }
}
