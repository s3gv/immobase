<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\UserInterface\Controller;

use App\Module\Property\Application\CloseProperty;
use App\Module\Property\Application\CountUnitLinks;
use App\Module\Property\Application\PreviewClosure;
use App\Module\Property\Application\SaveUnit;
use App\Module\Property\Domain\PropertyNeedsAClosingDate;
use App\Module\Property\Domain\PropertyPermissions;
use App\Module\Property\Domain\UnitUsage;
use App\Shared\Time\DateInput;
use DateTimeImmutable;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Die Seite einer Einheit — und das Loeschen.
 *
 * Angelegt und geaendert wird sie im Ablauf nebenan.
 */
#[IsGranted(PropertyPermissions::VIEW)]
final class UnitController extends AbstractController
{
    public function __construct(
        private readonly RequireProperty $property,
        private readonly UnitPage $page,
        private readonly OwnerRows $ownerRows,
        private readonly SaveUnit $units,
        private readonly CountUnitLinks $links,
        private readonly CloseProperty $close,
        private readonly PreviewClosure $preview,
    ) {
    }

    #[Route(
        '/objekte/{number}/einheiten/{unit}',
        name: 'app_unit_show',
        requirements: ['number' => '\d+', 'unit' => '\d+'],
        methods: ['GET'],
    )]
    public function show(int $number, int $unit, Request $request): Response
    {
        $property = ($this->property)($number);
        $found = $this->property->unit($property, $unit);
        $section = UnitFlow::known($request->query->getString('abschnitt'), $found);

        return $this->render('property/unit/'.$section.'.html.twig', [
            ...$this->page->frame($found, $section, UnitFlow::editable($found), 'app_unit_show'),
            ...$this->ownerRows->of($found),
            'usages' => UnitUsage::cases(),
            'today' => new DateTimeImmutable('today'),
            'links' => $this->links->forUnit($found->id()),
            'closing' => $this->preview->forUnit($found),
        ]);
    }

    /**
     * Eine Einheit abgeben: verkauft, aus der Verwaltung genommen.
     *
     * Geht auch, wenn das Objekt bleibt — bei Sondereigentumsverwaltung ist
     * das der Normalfall.
     */
    #[IsGranted(PropertyPermissions::EDIT)]
    #[Route(
        '/objekte/{number}/einheiten/{unit}/abgeben',
        name: 'app_unit_close',
        requirements: ['number' => '\d+', 'unit' => '\d+'],
        methods: ['POST'],
    )]
    public function close(int $number, int $unit, Request $request): Response
    {
        $property = ($this->property)($number);
        $found = $this->property->unit($property, $unit);

        if (!$this->isCsrfTokenValid('unit_closure_'.$unit, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }

        try {
            $this->close->unitOn($found, DateInput::orNull($request, 'closedOn'));
            $this->addFlash('success', 'property.unit.closed');
        } catch (PropertyNeedsAClosingDate) {
            $this->addFlash('error', 'property.error.closing_date_required');
        } catch (InvalidArgumentException) {
            $this->addFlash('error', 'property.error.closing_date_invalid');
        }

        return $this->redirectToRoute('app_unit_show', ['number' => $number, 'unit' => $unit]);
    }

    /** Wieder aufnehmen — die Mietverhaeltnisse bleiben beendet. */
    #[IsGranted(PropertyPermissions::EDIT)]
    #[Route(
        '/objekte/{number}/einheiten/{unit}/wieder-aufnehmen',
        name: 'app_unit_reopen',
        requirements: ['number' => '\d+', 'unit' => '\d+'],
        methods: ['POST'],
    )]
    public function reopen(int $number, int $unit, Request $request): Response
    {
        $property = ($this->property)($number);
        $found = $this->property->unit($property, $unit);

        if (!$this->isCsrfTokenValid('unit_closure_'.$unit, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }

        $this->close->reopenUnit($found);
        $this->addFlash('success', 'property.unit.reopened');

        return $this->redirectToRoute('app_unit_show', ['number' => $number, 'unit' => $unit]);
    }

    #[IsGranted(PropertyPermissions::DELETE)]
    #[Route(
        '/objekte/{number}/einheiten/{unit}/loeschen',
        name: 'app_unit_delete',
        requirements: ['number' => '\d+', 'unit' => '\d+'],
        methods: ['POST'],
    )]
    public function delete(int $number, int $unit, Request $request): Response
    {
        $property = ($this->property)($number);
        $found = $this->property->unit($property, $unit);

        if (!$this->isCsrfTokenValid('unit_delete_'.$unit, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }

        if ($this->links->anyFor($found->id()) || $found->status()->isPast()) {
            return $this->stillLinked($property->number(), $found->number());
        }

        $this->units->remove($found);
        $this->addFlash('success', 'property.unit.deleted');

        return $this->redirectToRoute('app_property_show', [
            'number' => $property->number(),
            'abschnitt' => PropertyPage::UNITS,
        ]);
    }

    /**
     * Auch wenn die Oberflaeche den Knopf abschaltet: die Pruefung gehoert in
     * den Controller. Ein abgeschalteter Knopf haelt niemanden auf, der die
     * Adresse kennt.
     *
     * Dasselbe gilt fuer eine abgegebene Einheit: was einmal verwaltet wurde,
     * verschwindet nicht — die Abrechnung braucht es noch.
     */
    private function stillLinked(int $number, int $unit): Response
    {
        $this->addFlash('error', 'property.unit.linked');

        return $this->redirectToRoute('app_unit_show', ['number' => $number, 'unit' => $unit]);
    }
}
