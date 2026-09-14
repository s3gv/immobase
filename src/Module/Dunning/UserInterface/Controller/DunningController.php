<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\UserInterface\Controller;

use App\Module\Dunning\Application\ClaimState;
use App\Module\Dunning\Application\SettleClaim;
use App\Module\Dunning\Application\StartClaim;
use App\Module\Dunning\Application\SurveyClaims;
use App\Module\Dunning\Application\SurveyOverdue;
use App\Module\Dunning\Contract\DunningOverview;
use App\Module\Dunning\Domain\BaseRateRepository;
use App\Module\Dunning\Domain\ClaimFilter;
use App\Module\Dunning\Domain\ClaimIsSettled;
use App\Module\Dunning\Domain\ClaimRepository;
use App\Module\Dunning\Domain\DunningPermissions;
use App\Module\Dunning\Domain\NoticeRepository;
use App\Module\Party\Contract\PartyDirectory;
use App\Module\Property\Contract\PropertyDirectory;
use App\Shared\Time\DateInput;
use App\Shared\Ui\Page;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Das Mahnwesen: Uebersicht, Detailseite, erledigt melden.
 *
 * Das Schreiben selbst entsteht im Ablauf nebenan — hier wird nachgesehen und
 * abgehakt.
 */
#[IsGranted(DunningPermissions::VIEW)]
final class DunningController extends AbstractController
{
    public function __construct(
        private readonly ClaimRepository $claims,
        private readonly NoticeRepository $notices,
        private readonly SurveyClaims $survey,
        private readonly SurveyOverdue $overdue,
        private readonly RequireClaim $claim,
        private readonly DunningView $view,
        private readonly SettleClaim $settle,
        private readonly StartClaim $start,
        private readonly DunningPage $page,
        private readonly PropertyDirectory $properties,
        private readonly PartyDirectory $parties,
        private readonly BaseRateRepository $rates,
        private readonly DunningOverview $dunning,
    ) {
    }

    #[Route('/finanzen/mahnwesen', name: 'app_dunning', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filter = ClaimFilter::of(
            $request->query->getString('objekt'),
            $request->query->getString('zustand'),
            $request->query->getString('q'),
        );
        $page = Page::of($request->query->getInt('page', 1), $this->claims->countMatching($filter));
        $today = new DateTimeImmutable('today');
        $states = $this->survey->states($this->claims->matching($filter, $page), $today);

        $narrowed = self::narrowed($states, $filter);
        $rates = $this->rates->all();

        return $this->render('dunning/index.html.twig', [
            'states' => $narrowed,
            'debtors' => $this->debtorsOf($narrowed),
            'overdue' => $this->overdue->on($today),
            'pressure' => $this->dunning->pressure(),
            'rateFrom' => $rates->latest(),
            'rateMissing' => $rates->missingFor($today),
            'page' => $page,
            'filter' => $filter,
            'url' => $this->page->listUrl($request),
            'properties' => $this->properties->all(),
            'trail' => $this->page->trail(),
        ]);
    }

    #[Route(
        '/finanzen/mahnwesen/{id}',
        name: 'app_dunning_show',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['GET'],
    )]
    public function show(string $id): Response
    {
        $claim = ($this->claim)($id);

        return $this->render('dunning/claim.html.twig', [
            ...$this->view->data($claim),
            'trail' => $this->page->trail(),
        ]);
    }

    /** Das Geld ist gekommen — an diesem Tag. */
    #[IsGranted(DunningPermissions::EDIT)]
    #[Route(
        '/finanzen/mahnwesen/{id}/erledigt',
        name: 'app_dunning_settle',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    public function settle(string $id, Request $request): Response
    {
        $claim = ($this->claim)($id);
        $this->guard($request);

        try {
            $this->settle->settle($claim, DateInput::orNull($request, 'settledOn') ?? new DateTimeImmutable('today'));
            $this->addFlash('success', 'dunning.settled');
        } catch (ClaimIsSettled $problem) {
            $this->addFlash('error', $problem->getMessage());
        }

        return $this->redirectToRoute('app_dunning_show', ['id' => $claim->id()]);
    }

    /** Eine Forderung, ueber die nie geschrieben wurde, darf verschwinden. */
    #[IsGranted(DunningPermissions::EDIT)]
    #[Route(
        '/finanzen/mahnwesen/{id}/loeschen',
        name: 'app_dunning_delete',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    public function delete(string $id, Request $request): Response
    {
        $claim = ($this->claim)($id);
        $this->guard($request);

        if ([] !== $this->notices->forClaims([$claim->id()])) {
            $this->addFlash('error', 'dunning.error.has_notices');

            return $this->redirectToRoute('app_dunning_show', ['id' => $claim->id()]);
        }

        $this->start->discard($claim);
        $this->addFlash('success', 'dunning.discarded');

        return $this->redirectToRoute('app_dunning');
    }

    /**
     * Die Namen der Schuldner — eine Abfrage fuer die ganze Seite.
     *
     * Je Zeile eine waeren bei fuenfzig Vorgaengen fuenfzig Abfragen fuer
     * fuenfzig Namen.
     *
     * @param list<ClaimState> $states
     *
     * @return array<string, string> Kennung der Forderung auf den Namen
     */
    private function debtorsOf(array $states): array
    {
        $partyIds = array_values(array_unique(array_map(
            static fn (ClaimState $state): string => $state->claim->debtor()->partyId(),
            $states,
        )));
        $parties = $this->parties->byIds($partyIds);
        $found = [];

        foreach ($states as $state) {
            $found[$state->claim->id()] = $parties[$state->claim->debtor()->partyId()]->displayName ?? '—';
        }

        return $found;
    }

    /**
     * Den Zustandsfilter anwenden.
     *
     * Er steht hier und nicht in der Abfrage: „Frist abgelaufen" haengt
     * daran, welches Schreiben zuletzt hinausging und welche Frist darauf
     * stand — beides steht nicht in der Tabelle der Forderungen.
     *
     * @param list<ClaimState> $states
     *
     * @return list<ClaimState>
     */
    private static function narrowed(array $states, ClaimFilter $filter): array
    {
        return match ($filter->state) {
            ClaimFilter::DUE => array_values(array_filter($states, static fn (ClaimState $s): bool => $s->isDue)),
            ClaimFilter::RUNNING => array_values(array_filter(
                $states,
                static fn (ClaimState $s): bool => !$s->isDue && null !== $s->level,
            )),
            ClaimFilter::OPEN => array_values(array_filter(
                $states,
                static fn (ClaimState $s): bool => null === $s->level,
            )),
            default => $states,
        };
    }

    private function guard(Request $request): void
    {
        if (!$this->isCsrfTokenValid('dunning', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }
    }
}
