<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\UserInterface\Controller;

use App\Module\Tenancy\Application\SaveTenancy;
use App\Module\Tenancy\Domain\DepositKind;
use App\Module\Tenancy\Domain\PaymentDue;
use App\Module\Tenancy\Domain\PaymentMethod;
use App\Module\Tenancy\Domain\Tenancy;
use App\Module\Tenancy\Domain\TenancyNeedsATenant;
use App\Module\Tenancy\Domain\TenancyPermissions;
use App\Module\Tenancy\Domain\UnitAlreadyLet;
use App\Module\Tenancy\Domain\UnitLetInThatPeriod;
use App\Module\Tenancy\Domain\UnknownUnit;
use App\Shared\Text\Trimmed;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Ein Mietverhaeltnis anlegen und bearbeiten — Schritt fuer Schritt.
 *
 * Wie beim Objekt speichert jeder Schritt sofort. Es gibt deshalb keinen
 * Zwischenstand in der Sitzung, kein „Abbrechen verwirft" und keinen
 * Unterschied zwischen Anlegen und Bearbeiten: nach dem ersten Schritt ist
 * beides dasselbe.
 *
 * Was das kostet, steht am Mietverhaeltnis: es ist ab da inaktiv in der Liste.
 * Was es bringt: man kann morgen weitermachen, und wer mitten drin einen
 * Mieter anlegen muss, verliert nichts.
 */
#[IsGranted(TenancyPermissions::EDIT)]
final class TenancyFlowController extends AbstractController
{
    public function __construct(
        private readonly RequireTenancy $tenancy,
        private readonly TenancyStepInput $input,
        private readonly SaveTenancy $save,
        private readonly TenancyFlowPage $page,
        private readonly TenancyView $view,
    ) {
    }

    #[Route('/miete/neu', name: 'app_tenancy_new', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        if (!$request->isMethod('POST')) {
            return $this->show(null, TenancyFlow::BASICS, $request);
        }

        $this->guard($request);
        $unitId = Trimmed::orNull($request->request->getString('unitId'));

        if (null === $unitId) {
            return $this->show(null, TenancyFlow::BASICS, $request, ['unitId' => 'tenancy.error.unit_required']);
        }

        return $this->started($unitId, $request);
    }

    #[Route(
        '/miete/{number}/bearbeiten/{step}',
        name: 'app_tenancy_edit',
        requirements: ['number' => '\d+', 'step' => '[a-z]+'],
        defaults: ['step' => TenancyFlow::BASICS],
        methods: ['GET', 'POST'],
    )]
    public function edit(int $number, string $step, Request $request): Response
    {
        $tenancy = ($this->tenancy)($number);
        $current = TenancyFlow::known($step);

        if (!$request->isMethod('POST')) {
            return $this->show($tenancy, $current, $request);
        }

        $this->guard($request);
        $errors = $this->input->apply($current, $request, $tenancy);

        if ([] !== $errors) {
            return $this->show($tenancy, $current, $request, $errors);
        }

        return $this->onwards($tenancy, $current, $request);
    }

    private function started(string $unitId, Request $request): Response
    {
        // Kein Einwand gegen eine vermietete Einheit: was hier entsteht, ist
        // ein Entwurf. Ob sie frei ist, entscheidet der letzte Schritt.
        try {
            $tenancy = $this->save->forUnit($unitId);
        } catch (UnknownUnit) {
            return $this->show(null, TenancyFlow::BASICS, $request, ['unitId' => 'tenancy.error.unit_unknown']);
        }

        $this->addFlash('success', 'tenancy.draft.saved');

        return $this->toStep($tenancy, TenancyFlow::TENANTS);
    }

    /**
     * Weiter, zurueck — oder fertig.
     *
     * „Fertig" setzt aktiv, und ab da ist die Einheit vermietet. Ohne Mieter
     * geht das nicht: aktiv heisst vermietet, und zwar an jemanden.
     */
    private function onwards(Tenancy $tenancy, string $step, Request $request): Response
    {
        if ('back' === $request->request->getString('direction')) {
            return $this->toStep($tenancy, TenancyFlow::previous($step) ?? TenancyFlow::BASICS);
        }

        $next = TenancyFlow::next($step);

        if (null !== $next) {
            return $this->toStep($tenancy, $next);
        }

        return $this->finished($tenancy, $step, $request);
    }

    private function finished(Tenancy $tenancy, string $step, Request $request): Response
    {
        try {
            $this->save->complete($tenancy);
        } catch (UnitAlreadyLet|UnitLetInThatPeriod $problem) {
            // Erst beim Speichern aufgefallen: der Entity Manager ist danach
            // geschlossen, also weiterleiten statt zeichnen — die naechste
            // Anfrage bringt einen frischen mit.
            if ($problem->whileSaving) {
                return $this->lost(LettingRefusal::keyFor($problem), 'app_tenancy_edit', [
                    'number' => $tenancy->number(),
                    'step' => $step,
                ]);
            }

            return $this->show($tenancy, $step, $request, ['flow' => LettingRefusal::keyFor($problem)]);
        } catch (TenancyNeedsATenant) {
            // Der Fehler gehoert zu einem anderen Schritt als dem, auf dem er
            // auffaellt. Deshalb `flow` und nicht `tenants`: er steht oben im
            // Ablauf und nicht an einem Feld, das hier gar nicht steht.
            return $this->show($tenancy, $step, $request, ['flow' => 'tenancy.error.tenant_required']);
        }

        $this->addFlash('success', 'tenancy.activated');

        return $this->redirectToRoute('app_tenancy_show', ['number' => $tenancy->number()]);
    }

    /**
     * Der verlorene Wettlauf: Meldung als Hinweis und zurueck auf die Seite.
     *
     * @param array<string, mixed> $parameters
     */
    private function lost(string $message, string $route, array $parameters = []): Response
    {
        $this->addFlash('error', $message);

        return $this->redirectToRoute($route, $parameters);
    }

    private function toStep(Tenancy $tenancy, string $step): Response
    {
        return $this->redirectToRoute('app_tenancy_edit', [
            'number' => $tenancy->number(),
            'step' => $step,
        ]);
    }

    private function guard(Request $request): void
    {
        if (!$this->isCsrfTokenValid('tenancy_flow', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }
    }

    /**
     * Ein Schritt, gezeichnet.
     *
     * Bei einem Fehler steht im Formular wieder, was abgeschickt wurde, und
     * nicht der gespeicherte Stand: wer sich vertippt hat, will die Stelle
     * verbessern und nicht alles noch einmal eingeben.
     *
     * @param array<string, string> $errors
     */
    private function show(?Tenancy $tenancy, string $step, Request $request, array $errors = []): Response
    {
        return $this->render('tenancy/steps/'.$step.'.html.twig', [
            ...$this->page->parameters($tenancy, $step, $errors),
            ...(null === $tenancy ? [] : $this->view->data($tenancy, new DateTimeImmutable('today'))),
            'submitted' => [] === $errors ? [] : $request->request->all(),
            'chosen' => $this->page->chosenUnit($tenancy, $request, $errors),
            'tenantsChosen' => $this->page->chosenTenants($tenancy, $request, $errors),
            'kinds' => DepositKind::cases(),
            'methods' => PaymentMethod::cases(),
            'dues' => PaymentDue::cases(),
        ]);
    }
}
