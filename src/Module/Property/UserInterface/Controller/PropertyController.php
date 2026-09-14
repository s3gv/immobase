<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\UserInterface\Controller;

use App\Module\Property\Application\CloseProperty;
use App\Module\Property\Application\PreviewClosure;
use App\Module\Property\Domain\ManagementMode;
use App\Module\Property\Domain\Property;
use App\Module\Property\Domain\PropertyFilter;
use App\Module\Property\Domain\PropertyNeedsAClosingDate;
use App\Module\Property\Domain\PropertyPermissions;
use App\Module\Property\Domain\PropertyRepository;
use App\Module\Property\Domain\PropertyStatus;
use App\Shared\Http\FormInput;
use App\Shared\Time\DateInput;
use App\Shared\Ui\Page;
use App\Shared\Ui\Past;
use DateTimeImmutable;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Die Objekte: Uebersicht, Objektseite, Abwickeln, Loeschen.
 *
 * Anlegen und Bearbeiten laufen ueber den Ablauf nebenan — sie sind eine
 * andere Sache als das Nachschlagen.
 */
#[IsGranted(PropertyPermissions::VIEW)]
final class PropertyController extends AbstractController
{
    public function __construct(
        private readonly PropertyRepository $properties,
        private readonly RequireProperty $property,
        private readonly PropertyPage $page,
        private readonly PropertyView $view,
        private readonly CloseProperty $close,
        private readonly PreviewClosure $preview,
    ) {
    }

    #[Route('/objekte', name: 'app_property', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filter = PropertyFilter::of(
            $request->query->getString('art'),
            $request->query->getString('q'),
            $request->query->getString('status'),
            Past::from($request)->shown,
        );

        $page = Page::of(FormInput::queryIntOrNull($request, 'page') ?? 1, $this->properties->countMatching($filter));
        $properties = $this->properties->matching($filter, $page);

        return $this->render('property/index.html.twig', [
            'properties' => $properties,
            'units' => $this->properties->unitCounts(array_map(
                static fn (Property $property): string => $property->id(),
                $properties,
            )),
            'page' => $page,
            'filter' => $filter,
            'modes' => ManagementMode::cases(),
            'statuses' => PropertyStatus::cases(),
            'url' => $this->page->listUrl($request),
            'trail' => $this->page->trail(null),
        ]);
    }

    #[Route('/objekte/{number}', name: 'app_property_show', requirements: ['number' => '\d+'], methods: ['GET'])]
    public function show(int $number, Request $request): Response
    {
        $property = ($this->property)($number);
        $section = PropertyPage::known($request->query->getString('abschnitt'), $property);

        return $this->render('property/detail/'.$section.'.html.twig', [
            ...$this->page->sections($property, $section),
            ...$this->view->data($property),
            'property' => $property,
            'closing' => $this->preview->forProperty($property),
            'period' => self::periodOf($property),
            'trail' => $this->page->trail($property),
        ]);
    }

    /**
     * Abwickeln: der Verwaltervertrag ist ausgelaufen.
     *
     * Das greift durch — Einheiten, Mietverhaeltnisse, alles, was daran
     * haengt. Deshalb der Stichtag, und deshalb die Rueckfrage davor.
     */
    #[IsGranted(PropertyPermissions::EDIT)]
    #[Route('/objekte/{number}/abwickeln', name: 'app_property_close', requirements: ['number' => '\d+'], methods: ['POST'])]
    public function close(int $number, Request $request): Response
    {
        $property = ($this->property)($number);
        $this->guard($request, 'property_closure_'.$number);

        try {
            $this->close->on($property, DateInput::orNull($request, 'closedOn'));
            $this->addFlash('success', 'property.closed');
        } catch (PropertyNeedsAClosingDate) {
            $this->addFlash('error', 'property.error.closing_date_required');
        } catch (InvalidArgumentException) {
            $this->addFlash('error', 'property.error.closing_date_invalid');
        }

        return $this->redirectToRoute('app_property_show', ['number' => $number]);
    }

    /** Wieder aufnehmen — nur das Objekt; die Einheiten bleiben beendet. */
    #[IsGranted(PropertyPermissions::EDIT)]
    #[Route('/objekte/{number}/wieder-aufnehmen', name: 'app_property_reopen', requirements: ['number' => '\d+'], methods: ['POST'])]
    public function reopen(int $number, Request $request): Response
    {
        $property = ($this->property)($number);
        $this->guard($request, 'property_closure_'.$number);

        $this->close->reopen($property);
        $this->addFlash('success', 'property.reopened');

        return $this->redirectToRoute('app_property_show', ['number' => $number]);
    }

    #[IsGranted(PropertyPermissions::DELETE)]
    #[Route('/objekte/{number}/loeschen', name: 'app_property_delete', requirements: ['number' => '\d+'], methods: ['POST'])]
    public function delete(int $number, Request $request): Response
    {
        $property = ($this->property)($number);
        $this->guard($request, 'property_delete_'.$number);

        // Auch wenn die Oberflaeche den Knopf abschaltet: die Pruefung gehoert
        // hierher. Was je verwaltet wurde, wird abgewickelt und nicht
        // geloescht — die Abrechnung des laufenden Jahres braucht es noch.
        if (!$property->status()->isDraft()) {
            $this->addFlash('error', 'property.not_deletable');

            return $this->redirectToRoute('app_property_show', ['number' => $number]);
        }

        $this->properties->remove($property);
        $this->addFlash('success', 'property.deleted');

        return $this->redirectToRoute('app_property');
    }

    /**
     * Das laufende Wirtschaftsjahr — von wann bis wann.
     *
     * Gerechnet und nicht gespeichert: die Regel steht am Objekt, und das
     * Jahr ergibt sich aus ihr und dem heutigen Tag.
     *
     * @return array{from: DateTimeImmutable, to: DateTimeImmutable}
     */
    private static function periodOf(Property $property): array
    {
        $fiscalYear = $property->accounting()->fiscalYear();
        $year = $fiscalYear->yearOf(new DateTimeImmutable('today'));

        return ['from' => $fiscalYear->beginsIn($year), 'to' => $fiscalYear->endsIn($year)];
    }

    private function guard(Request $request, string $token): void
    {
        if (!$this->isCsrfTokenValid($token, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }
    }
}
