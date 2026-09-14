<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\UserInterface\Controller;

use App\Module\Property\Application\SaveUnit;
use App\Module\Property\Domain\Property;
use App\Module\Property\Domain\PropertyPermissions;
use App\Module\Property\Domain\Unit;
use App\Module\Property\Domain\UnitUsage;
use App\Shared\Text\Trimmed;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Eine Einheit anlegen und bearbeiten.
 *
 * Wie beim Objekt: jeder Schritt speichert sofort. Der erste legt an, danach
 * gibt es keinen Unterschied mehr zwischen Anlegen und Bearbeiten.
 */
#[IsGranted(PropertyPermissions::EDIT)]
final class UnitFlowController extends AbstractController
{
    public function __construct(
        private readonly RequireProperty $property,
        private readonly SaveUnit $units,
        private readonly UnitStepInput $input,
        private readonly UnitPage $page,
        private readonly OwnerRows $ownerRows,
    ) {
    }

    #[Route(
        '/objekte/{number}/einheiten/neu',
        name: 'app_unit_new',
        requirements: ['number' => '\d+'],
        methods: ['GET', 'POST'],
    )]
    public function create(int $number, Request $request): Response
    {
        $property = ($this->property)($number);

        if (!$request->isMethod('POST')) {
            return $this->blank($property, $request);
        }

        $this->guard($request);
        $label = Trimmed::orNull($request->request->getString('label'));

        if (null === $label) {
            return $this->blank($property, $request, ['label' => 'property.error.name_required']);
        }

        $unit = $this->units->create($property, $label, self::usage($request));
        $this->addFlash('success', 'property.unit.created');

        return $this->toStep($unit, UnitFlow::next(UnitFlow::BASICS, $unit) ?? UnitFlow::DETAIL);
    }

    #[Route(
        '/objekte/{number}/einheiten/{unit}/bearbeiten/{step}',
        name: 'app_unit_edit',
        requirements: ['number' => '\d+', 'unit' => '\d+', 'step' => '[a-z]+'],
        defaults: ['step' => UnitFlow::BASICS],
        methods: ['GET', 'POST'],
    )]
    public function edit(int $number, int $unit, string $step, Request $request): Response
    {
        $property = ($this->property)($number);
        $found = $this->property->unit($property, $unit);
        $current = UnitFlow::known($step, $found);

        if (!$request->isMethod('POST')) {
            return $this->show($found, $current, $request);
        }

        $this->guard($request);
        $errors = $this->input->apply($current, $request, $found);

        if ([] !== $errors) {
            return $this->show($found, $current, $request, $errors);
        }

        return $this->onwards($found, $current, $request);
    }

    private function onwards(Unit $unit, string $step, Request $request): Response
    {
        if ('back' === $request->request->getString('direction')) {
            return $this->toStep($unit, UnitFlow::previous($step, $unit) ?? UnitFlow::BASICS);
        }

        $next = UnitFlow::next($step, $unit);

        if (null !== $next) {
            return $this->toStep($unit, $next);
        }

        $this->addFlash('success', 'property.unit.saved');

        return $this->redirectToRoute('app_unit_show', [
            'number' => $unit->property()->number(),
            'unit' => $unit->number(),
        ]);
    }

    private function toStep(Unit $unit, string $step): Response
    {
        return $this->redirectToRoute('app_unit_edit', [
            'number' => $unit->property()->number(),
            'unit' => $unit->number(),
            'step' => $step,
        ]);
    }

    private static function usage(Request $request): UnitUsage
    {
        return UnitUsage::tryFrom($request->request->getString('usage')) ?? UnitUsage::Residential;
    }

    private function guard(Request $request): void
    {
        if (!$this->isCsrfTokenValid('unit_flow', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }
    }

    /**
     * Kontakte, die in der Liste stehen sollen, ohne zugeordnet zu sein: der
     * gerade angelegte aus `?neu=` und die, die vor einem Fehler schon im
     * Formular standen.
     *
     * Die Kontakte und nicht die Zeilenkennungen: eine Zeile heisst nach
     * ihrem Eigentumszeitraum, und nachgeschlagen wird der Mensch.
     *
     * @param array<string, array{party: string, mea: string, von: string, bis: string}> $typed
     *
     * @return list<string>
     */
    private static function alsoShow(Request $request, array $typed): array
    {
        $fresh = Trimmed::orNull($request->query->getString('neu'));

        return null === $fresh ? [] : [$fresh];
    }

    /**
     * @param array<string, string> $errors
     */
    private function blank(Property $property, Request $request, array $errors = []): Response
    {
        return $this->render('property/unit/steps/neu.html.twig', [
            'property' => $property,
            'errors' => $errors,
            'submitted' => [] === $errors ? [] : $request->request->all(),
            'usages' => UnitUsage::cases(),
            'action' => $this->generateUrl('app_unit_new', ['number' => $property->number()]),
            'cancel' => $this->generateUrl('app_property_edit', [
                'number' => $property->number(),
                'step' => PropertyFlow::UNITS,
            ]),
            'trail' => [],
        ]);
    }

    /**
     * Ein Schritt, gezeichnet.
     *
     * Bei einem Fehler steht im Formular wieder, was abgeschickt wurde:
     * niemand soll wegen eines Tippfehlers alles neu eingeben.
     *
     * @param array<string, string> $errors
     */
    private function show(Unit $unit, string $step, Request $request, array $errors = []): Response
    {
        $typed = [] === $errors ? [] : UnitStepInput::sharesFrom($request);

        return $this->render('property/unit/steps/'.$step.'.html.twig', [
            ...$this->page->frame($unit, $step, UnitFlow::editable($unit), 'app_unit_edit'),
            // Ein gerade angelegter Kontakt kommt als `?neu=` zurueck — siehe
            // ReturnPath. Er steht dann gleich in der Liste.
            ...$this->ownerRows->of($unit, self::alsoShow($request, $typed), $typed),
            'errors' => $errors,
            'submitted' => [] === $errors ? [] : $request->request->all(),
            'usages' => UnitUsage::cases(),
            'isLast' => null === UnitFlow::next($step, $unit),
            'hasPrevious' => null !== UnitFlow::previous($step, $unit),
            'action' => $this->generateUrl('app_unit_edit', [
                'number' => $unit->property()->number(),
                'unit' => $unit->number(),
                'step' => $step,
            ]),
            'cancel' => $this->generateUrl('app_unit_show', [
                'number' => $unit->property()->number(),
                'unit' => $unit->number(),
            ]),
        ]);
    }
}
