<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\UserInterface\Controller;

use App\Module\Property\Application\SaveProperty;
use App\Module\Property\Domain\HeatingSystem;
use App\Module\Property\Domain\HeatingType;
use App\Module\Property\Domain\HotWater;
use App\Module\Property\Domain\ManagementMode;
use App\Module\Property\Domain\MeaDenominator;
use App\Module\Property\Domain\Property;
use App\Module\Property\Domain\PropertyPermissions;
use App\Shared\Time\Months;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Ein Objekt anlegen und bearbeiten — Schritt fuer Schritt.
 *
 * Anders als bei den Stammdaten speichert jeder Schritt sofort. Es gibt
 * deshalb keinen Zwischenstand in der Sitzung, kein „Abbrechen verwirft" und
 * keinen Unterschied zwischen Anlegen und Bearbeiten: nach dem ersten Schritt
 * ist beides dasselbe.
 *
 * Was das kostet, steht am Objekt: es ist ab da ein Entwurf in der Liste. Was
 * es bringt: man kann morgen weitermachen, und wer mitten drin einen
 * Eigentuemer anlegen muss, verliert nichts.
 */
#[IsGranted(PropertyPermissions::EDIT)]
final class PropertyFlowController extends AbstractController
{
    public function __construct(
        private readonly RequireProperty $property,
        private readonly PropertyStepInput $input,
        private readonly SaveProperty $save,
        private readonly PropertyFlowPage $page,
        private readonly PropertyView $view,
    ) {
    }

    #[Route('/objekte/neu', name: 'app_property_new', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        if (!$request->isMethod('POST')) {
            return $this->show(null, PropertyFlow::NAME, $request);
        }

        $this->guard($request);
        $outcome = $this->input->create($request);

        if (null === $outcome['property']) {
            return $this->show(null, PropertyFlow::NAME, $request, $outcome['errors']);
        }

        $this->addFlash('success', 'property.draft.saved');

        return $this->toStep($outcome['property'], PropertyFlow::ADDRESS);
    }

    #[Route(
        '/objekte/{number}/bearbeiten/{step}',
        name: 'app_property_edit',
        requirements: ['number' => '\d+', 'step' => '[a-z]+'],
        defaults: ['step' => PropertyFlow::NAME],
        methods: ['GET', 'POST'],
    )]
    public function edit(int $number, string $step, Request $request): Response
    {
        $property = ($this->property)($number);
        $current = PropertyFlow::known($step, $property);

        if (!$request->isMethod('POST')) {
            return $this->show($property, $current, $request);
        }

        $this->guard($request);
        $errors = $this->input->apply($current, $request, $property);

        if ([] !== $errors) {
            return $this->show($property, $current, $request, $errors);
        }

        return $this->onwards($property, $current, $request);
    }

    /**
     * Weiter, zurueck — oder fertig.
     *
     * „Fertig" macht aus dem Entwurf ein Objekt. Bei einem, das schon aktiv
     * ist, aendert es nichts und fuehrt nur zurueck auf die Objektseite.
     *
     * Ohne Einheit geht es nicht: ein Objekt ohne Einheit laesst sich nicht
     * vermieten, nicht abrechnen und nicht aufteilen. Der Entwurf darf
     * trotzdem leer bleiben — sonst waere das Anlegen wieder ein Weg, den man
     * in einem Zug gehen muss.
     */
    private function onwards(Property $property, string $step, Request $request): Response
    {
        if ('back' === $request->request->getString('direction')) {
            return $this->toStep($property, PropertyFlow::previous($step, $property) ?? PropertyFlow::NAME);
        }

        $next = PropertyFlow::next($step, $property);

        if (null !== $next) {
            return $this->toStep($property, $next);
        }

        if ([] === $property->units()) {
            return $this->show($property, $step, $request, ['units' => 'property.error.unit_required']);
        }

        $this->save->complete($property);
        $this->addFlash('success', 'property.activated');

        return $this->redirectToRoute('app_property_show', ['number' => $property->number()]);
    }

    private function toStep(Property $property, string $step): Response
    {
        return $this->redirectToRoute('app_property_edit', [
            'number' => $property->number(),
            'step' => $step,
        ]);
    }

    private function guard(Request $request): void
    {
        if (!$this->isCsrfTokenValid('property_flow', $request->request->getString('_token'))) {
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
    private function show(?Property $property, string $step, Request $request, array $errors = []): Response
    {
        return $this->render('property/steps/'.$step.'.html.twig', [
            ...$this->page->parameters($property, $step, $errors),
            ...(null === $property ? [] : $this->view->data($property)),
            'submitted' => [] === $errors ? [] : $request->request->all(),
            'modes' => ManagementMode::cases(),
            'heatingTypes' => HeatingType::cases(),
            'heatingSystems' => HeatingSystem::cases(),
            'hotWaters' => HotWater::cases(),
            'denominators' => MeaDenominator::cases(),
            'months' => Months::forChoice(),
        ]);
    }
}
