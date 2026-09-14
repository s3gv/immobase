<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\UserInterface\Controller;

use App\Module\Tenancy\Application\CountTenancyLinks;
use App\Module\Tenancy\Application\SaveTenancy;
use App\Module\Tenancy\Domain\TenancyNeedsAnEnd;
use App\Module\Tenancy\Domain\TenancyNeedsATenant;
use App\Module\Tenancy\Domain\TenancyPermissions;
use App\Module\Tenancy\Domain\TenancyRepository;
use App\Module\Tenancy\Domain\TenancyStatus;
use App\Module\Tenancy\Domain\UnitAlreadyLet;
use App\Module\Tenancy\Domain\UnitLetInThatPeriod;
use App\Module\Tenancy\Infrastructure\TenancyQueries;
use App\Shared\Http\FormInput;
use App\Shared\Time\DateInput;
use App\Shared\Ui\Page;
use App\Shared\Ui\Sort;
use DateTimeImmutable;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Die Mietverhaeltnisse: Uebersicht, Mietseite, Beenden, Loeschen.
 *
 * Anlegen und Bearbeiten laufen ueber den Ablauf nebenan — sie sind eine
 * andere Sache als das Nachschlagen.
 */
#[IsGranted(TenancyPermissions::VIEW)]
final class TenancyController extends AbstractController
{
    /** Voreinstellung der Uebersicht: das Neueste zuerst. */
    private const string DEFAULT_SORT = 'nummer';

    public function __construct(
        private readonly TenancyRepository $tenancies,
        private readonly RequireTenancy $tenancy,
        private readonly TenancyPage $page,
        private readonly TenancyView $view,
        private readonly TenancyFilters $filters,
        private readonly SaveTenancy $save,
        private readonly CountTenancyLinks $links,
    ) {
    }

    #[Route('/miete', name: 'app_tenancy', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filter = $this->filters->from($request);
        $sort = Sort::of(
            $request->query->getString('sortieren'),
            $request->query->getString('richtung'),
            array_keys(TenancyQueries::SORTABLE),
            Sort::by(self::DEFAULT_SORT, Sort::DESCENDING),
        );

        $page = Page::of(FormInput::queryIntOrNull($request, 'page') ?? 1, $this->tenancies->countMatching($filter));

        return $this->render('tenancy/index.html.twig', [
            'rows' => $this->view->rows($this->tenancies->matching($filter, $page, $sort), self::today()),
            'page' => $page,
            'filter' => $filter,
            'sort' => $sort,
            'sortUrls' => $this->page->sortUrls($request, $sort, array_keys(TenancyQueries::SORTABLE)),
            'statuses' => TenancyStatus::cases(),
            'properties' => $this->filters->properties(),
            'chosenProperty' => $request->query->getString('objekt'),
            'url' => $this->page->listUrl($request),
            'trail' => $this->page->trail(null),
        ]);
    }

    #[Route('/miete/{number}', name: 'app_tenancy_show', requirements: ['number' => '\d+'], methods: ['GET'])]
    public function show(int $number, Request $request): Response
    {
        $tenancy = ($this->tenancy)($number);
        $section = TenancyFlow::known($request->query->getString('abschnitt'));

        return $this->render('tenancy/detail/'.$section.'.html.twig', [
            ...$this->page->sections($tenancy, $section),
            ...$this->view->data($tenancy, self::today()),
            'links' => $this->links->forTenancy($tenancy->id()),
            'trail' => $this->page->trail($tenancy),
        ]);
    }

    /**
     * Aktiv setzen — den Entwurf zum ersten Mal, das Beendete wieder.
     *
     * Beides ist derselbe Schritt und dieselbe Pruefung: die Einheit muss
     * frei sein, und der Zeitraum darf keinem anderen in die Quere kommen.
     */
    #[IsGranted(TenancyPermissions::EDIT)]
    #[Route('/miete/{number}/aktivieren', name: 'app_tenancy_activate', requirements: ['number' => '\d+'], methods: ['POST'])]
    public function activate(int $number, Request $request): Response
    {
        $tenancy = ($this->tenancy)($number);
        $this->guard($request, 'tenancy_status_'.$number);

        // Der Zustand vorher, denn nach dem Aufruf ist er in beiden Faellen
        // derselbe — und die Meldung soll sagen, was passiert ist.
        $first = $tenancy->status()->isDraft();

        try {
            $first ? $this->save->complete($tenancy) : $this->save->reopen($tenancy);
            $this->addFlash('success', $first ? 'tenancy.activated' : 'tenancy.reopened');
        } catch (UnitAlreadyLet|UnitLetInThatPeriod $problem) {
            // Ob vorher nachgeschlagen oder erst von der Datenbank gemeldet:
            // hier wird ohnehin weitergeleitet, und die Absage ist dieselbe.
            $this->addFlash('error', LettingRefusal::keyFor($problem));
        } catch (TenancyNeedsATenant) {
            $this->addFlash('error', 'tenancy.error.tenant_required');
        }

        return $this->redirectToRoute('app_tenancy_show', ['number' => $number]);
    }

    /**
     * Beenden — mit dem Tag, an dem es zu Ende war.
     *
     * Das Datum ist Pflicht und nicht bloss eine Vorgabe: abgerechnet wird
     * das vergangene Jahr, und ein Mietverhaeltnis ohne Ende traegt keinen
     * Zeitraum, in den sich etwas einordnen liesse.
     */
    #[IsGranted(TenancyPermissions::EDIT)]
    #[Route('/miete/{number}/beenden', name: 'app_tenancy_end', requirements: ['number' => '\d+'], methods: ['POST'])]
    public function end(int $number, Request $request): Response
    {
        $tenancy = ($this->tenancy)($number);
        $this->guard($request, 'tenancy_status_'.$number);

        try {
            $this->save->end($tenancy, DateInput::orNull($request, 'endsOn'));
            $this->addFlash('success', 'tenancy.ended');
        } catch (TenancyNeedsAnEnd) {
            $this->addFlash('error', 'tenancy.error.end_required');
        } catch (InvalidArgumentException) {
            $this->addFlash('error', 'tenancy.error.end_invalid');
        }

        return $this->redirectToRoute('app_tenancy_show', ['number' => $number]);
    }

    #[IsGranted(TenancyPermissions::DELETE)]
    #[Route('/miete/{number}/loeschen', name: 'app_tenancy_delete', requirements: ['number' => '\d+'], methods: ['POST'])]
    public function delete(int $number, Request $request): Response
    {
        $tenancy = ($this->tenancy)($number);

        if (!$this->isCsrfTokenValid('tenancy_delete_'.$number, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }

        // Auch wenn die Oberflaeche den Knopf abschaltet: die Pruefung gehoert
        // hierher. Ein abgeschalteter Knopf haelt niemanden auf, der die
        // Adresse kennt.
        if ($this->links->anyFor($tenancy->id())) {
            $this->addFlash('error', 'tenancy.linked');

            return $this->redirectToRoute('app_tenancy_show', ['number' => $number]);
        }

        $this->tenancies->remove($tenancy);
        $this->addFlash('success', 'tenancy.deleted');

        return $this->redirectToRoute('app_tenancy');
    }

    private function guard(Request $request, string $token): void
    {
        if (!$this->isCsrfTokenValid($token, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }
    }

    /** Ohne Uhrzeit: eine Mietstufe gilt ab einem Tag, nicht ab einem Moment. */
    private static function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('today');
    }
}
