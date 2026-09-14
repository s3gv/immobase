<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\UserInterface\Controller;

use App\Module\Portal\Application\MyData;
use App\Module\Portal\Application\WhatIsMine;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Das Portal: vier Bereiche, alle lesend.
 *
 * **Keine Kennung in der Adresse.** Es gibt hier nichts nachzuschlagen — was
 * gezeigt wird, folgt aus dem Angemeldeten. Wer eine fremde Kennung eintippen
 * wollte, faende keine Stelle dafuer.
 *
 * Der Zugang haengt nicht an einem Recht aus dem Katalog, sondern an
 * `ROLE_PORTAL` in `access_control`: ein Portalkonto bekommt `ROLE_USER` gar
 * nicht erst, und damit ist es umgekehrt aus allem anderen ausgesperrt.
 */
final class PortalController extends AbstractController
{
    public function __construct(
        private readonly MyData $mine,
        private readonly WhatIsMine $today,
        private readonly PortalPage $page,
    ) {
    }

    #[Route('/portal', name: 'app_portal_home', methods: ['GET'])]
    public function home(): Response
    {
        return $this->redirectToRoute('app_portal', ['step' => PortalFlow::DATA]);
    }

    // Die Bereiche als Aufzaehlung und nicht als `[a-z]+`: sonst schluckt
    // diese Route auch „konto" und jede andere Portalseite, die spaeter
    // dazukommt.
    #[Route(
        '/portal/{step}',
        name: 'app_portal',
        requirements: ['step' => 'daten|objekte|einheiten|mietverhaeltnisse'],
        methods: ['GET'],
    )]
    public function show(string $step): Response
    {
        $current = PortalFlow::known($step);
        $party = $this->mine->party();

        return $this->render('portal/'.$current.'.html.twig', [
            ...$this->page->frame($current, null === $party ? '' : $party->displayName),
            'party' => $party,
            'properties' => $this->mine->properties(),
            'owned' => $this->mine->ownedUnits(),
            'tenancies' => $this->mine->tenancies(),
            'today' => $this->mine->today(),
            // Vorschlagen darf nur, wem es heute gehoert. Was frueher einmal
            // ihm gehoerte, steht weiter da — mit seinem Zeitraum und ohne
            // Knopf.
            'myUnitIds' => $this->today->unitIds(),
            'myPropertyIds' => $this->today->propertyIds(),
        ]);
    }
}
