<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Application;

use App\Module\Party\Contract\PartyBrief;
use App\Module\Party\Contract\PartyDirectory;
use App\Module\Property\Contract\UnitBrief;
use App\Module\Property\Contract\UnitDirectory;
use App\Module\Tenancy\Domain\Tenancy;
use App\Module\Tenancy\Domain\TenancyFilter;
use App\Module\Tenancy\Domain\TenancyPermissions;
use App\Module\Tenancy\Domain\TenancyRepository;
use App\Shared\Search\SearchesRecords;
use App\Shared\Search\SearchHit;
use App\Shared\Search\SearchTerm;
use App\Shared\Ui\Page;
use App\Shared\Ui\Sort;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Die Mietverhaeltnisse in der zentralen Suche.
 *
 * Ein Mietverhaeltnis hat selbst kaum Text: es hat eine Nummer, eine Einheit
 * und seine Mieter. Gesucht wird darum zuerst dort — welche Einheit
 * „Rosenweg" heisst, weiss das Objektmodul, welcher Kontakt „Muster" heisst,
 * das Stammdatenmodul. Dieselbe Uebersetzung wie in der Liste des Moduls;
 * ein zweiter Weg dahin liefe frueher oder spaeter anders.
 */
#[AsTaggedItem(priority: 70)]
final readonly class TenancySearch implements SearchesRecords
{
    /** Genug, um eine Suche brauchbar zu machen, ohne die Abfrage zu sprengen. */
    private const int MAX_MATCHES = 200;

    public function __construct(
        private TenancyRepository $tenancies,
        private UnitDirectory $units,
        private PartyDirectory $parties,
        private AuthorizationCheckerInterface $mayView,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function kindKey(): string
    {
        return 'search.kind.tenancy';
    }

    public function listUrl(SearchTerm $term): string
    {
        return $this->urls->generate('app_tenancy', ['q' => $term->raw]);
    }

    public function matching(SearchTerm $term, int $limit): array
    {
        if (!$this->mayView->isGranted(TenancyPermissions::VIEW)) {
            return [];
        }

        // Eine Seite und davon die ersten `$limit`: `Page` zaehlt in festen
        // Seitengroessen, und die Suche will eine kurze Liste und keine Seite.
        $found = $this->tenancies->matching($this->filter($term), Page::of(1, Page::PER_PAGE), Sort::by('nummer'));
        $shown = \array_slice($found, 0, $limit);

        // Die Einheiten fuer alle Treffer auf einmal: eine je Treffer waeren
        // zwanzig Abfragen — genau das Muster, vor dem `byIds()` warnt.
        $units = $this->units->byIds(array_map(
            static fn (Tenancy $tenancy): string => $tenancy->unitId(),
            $shown,
        ));

        return array_map(
            fn (Tenancy $tenancy): SearchHit => $this->hit($tenancy, $units),
            $shown,
        );
    }

    /**
     * Der Suchtext, uebersetzt in Kennungen.
     *
     * Welche Einheit „Rosenweg" heisst, weiss das Objektmodul, welcher
     * Kontakt „Muster" heisst, das Stammdatenmodul. Gesucht wird mit dem
     * aufbereiteten Text und nicht mit dem getippten: `raw` traegt noch die
     * Platzhalter, die in einem LIKE etwas anderes bedeuten als ein Zeichen.
     */
    private function filter(SearchTerm $term): TenancyFilter
    {
        return TenancyFilter::of(
            null,
            withPast: true,
            search: $term->text,
            matchingUnits: self::ids($this->units->search($term->text, self::MAX_MATCHES)),
            matchingParties: self::ids($this->parties->search($term->text, self::MAX_MATCHES)),
        );
    }

    /**
     * @param array<string, UnitBrief> $units
     */
    private function hit(Tenancy $tenancy, array $units): SearchHit
    {
        $unit = $units[$tenancy->unitId()] ?? null;

        return new SearchHit(
            title: $unit->label ?? '',
            subtitle: $unit->address ?? '',
            reference: (string) $tenancy->number(),
            url: $this->urls->generate('app_tenancy_show', ['number' => $tenancy->number()]),
        );
    }

    /**
     * @param list<UnitBrief>|list<PartyBrief> $found
     *
     * @return list<string>
     */
    private static function ids(array $found): array
    {
        return array_map(static fn (UnitBrief|PartyBrief $brief): string => $brief->id, $found);
    }
}
