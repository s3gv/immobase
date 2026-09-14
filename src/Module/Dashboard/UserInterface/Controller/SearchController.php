<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dashboard\UserInterface\Controller;

use App\Module\Dashboard\Application\FindsEverything;
use App\Module\Dashboard\Contract\SearchGroup;
use App\Shared\Search\SearchTerm;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Die eine Suche: ein Feld, alle Module.
 *
 * Zwei Wege durch dieselbe Maschinerie. Die Vorschlaege sind fuer „wo ist das
 * jetzt" — kurz, sofort, ohne Seitenaufbau. Die Trefferseite ist fuer „zeig
 * mir alles dazu" und traegt je Art den Weg in die Liste des Moduls, wo
 * geblaettert wird.
 *
 * Geblaettert wird hier absichtlich nicht: ueber Module hinweg zu blaettern
 * hiesse, alle Treffer aller Module zu holen, um die dritte Seite zeigen zu
 * koennen.
 */
final class SearchController extends AbstractController
{
    public function __construct(
        private readonly FindsEverything $search,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/suche', name: 'app_search', methods: ['GET'])]
    public function results(Request $request): Response
    {
        $term = SearchTerm::orNull($request->query->getString('q'));

        return $this->render('search/index.html.twig', [
            'term' => $term,
            'typed' => trim($request->query->getString('q')),
            'groups' => null === $term ? [] : $this->search->matching($term, FindsEverything::LISTED),
            'trail' => [
                ['label' => 'ImmoBase', 'url' => $this->generateUrl('app_dashboard')],
                ['label' => $this->translator->trans('search.heading'), 'url' => null],
            ],
        ]);
    }

    /**
     * Was beim Tippen unter dem Feld aufklappt.
     *
     * Uebersetzt wird hier und nicht im Browser: die Arten heissen in jeder
     * Sprache anders, und eine zweite Uebersetzungsdatei in JavaScript waere
     * die, die als Erste veraltet.
     */
    #[Route('/suche/vorschlaege', name: 'app_search_suggest', methods: ['GET'])]
    public function suggestions(Request $request): JsonResponse
    {
        $term = SearchTerm::orNull($request->query->getString('q'));

        if (null === $term) {
            return new JsonResponse(['groups' => []]);
        }

        return new JsonResponse([
            'groups' => array_map(
                $this->asData(...),
                $this->search->matching($term, FindsEverything::SUGGESTED),
            ),
            'allUrl' => $this->generateUrl('app_search', ['q' => $term->raw]),
        ]);
    }

    /**
     * @return array{kind: string, hits: list<array{title: string, subtitle: string, reference: string, url: string}>}
     */
    private function asData(SearchGroup $group): array
    {
        return [
            'kind' => $this->translator->trans($group->kindKey),
            'hits' => array_map(static fn ($hit): array => [
                'title' => $hit->title,
                'subtitle' => $hit->subtitle,
                'reference' => $hit->reference,
                'url' => $hit->url,
            ], $group->hits),
        ];
    }
}
