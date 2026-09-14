<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\BillingPermissions;
use App\Module\Billing\Domain\DocumentHit;
use App\Module\Billing\Domain\DocumentSearch;
use App\Shared\Search\SearchesRecords;
use App\Shared\Search\SearchHit;
use App\Shared\Search\SearchTerm;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Die Schreiben der Abrechnung in der zentralen Suche.
 *
 * **Eine Gruppe fuer alle Arten.** Abrechnung, Wirtschaftsplan, Budgetplan
 * und Vermoegensbericht stehen zusammen, und was ein Treffer ist, sagt seine
 * Zeile. Vier Gruppen mit je einem Eintrag waeren eine Gliederung, wo jemand
 * eine Antwort sucht: wer „2026" tippt, will die Schreiben zu 2026 sehen.
 *
 * Darum fuehrt hier auch kein „Alle anzeigen" irgendwohin — es gaebe vier
 * Listen, und ein Link auf eine davon waere geraten.
 */
#[AsTaggedItem(priority: 60)]
final readonly class BillingSearch implements SearchesRecords
{
    public function __construct(
        private DocumentSearch $documents,
        private AuthorizationCheckerInterface $mayView,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    public function kindKey(): string
    {
        return 'search.kind.billing';
    }

    public function listUrl(SearchTerm $term): string
    {
        return '';
    }

    public function matching(SearchTerm $term, int $limit): array
    {
        if (!$this->mayView->isGranted(BillingPermissions::VIEW)) {
            return [];
        }

        return array_map(
            fn (DocumentHit $hit): SearchHit => new SearchHit(
                title: '' === $hit->label ? $this->translator->trans($hit->kind->labelKey()) : $hit->label,
                subtitle: $this->translator->trans($hit->kind->labelKey()).' · '.$hit->year,
                reference: (string) $hit->propertyNumber,
                url: $this->urls->generate($hit->kind->route(), ['id' => $hit->id]),
            ),
            $this->documents->anywhere($term, $limit),
        );
    }
}
