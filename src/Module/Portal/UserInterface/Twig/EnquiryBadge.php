<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\UserInterface\Twig;

use App\Module\Portal\Application\EnquiryPressure;
use App\Module\Portal\Domain\Enquiry;
use App\Module\Portal\Domain\EnquiryRepository;
use App\Module\Portal\Domain\PortalPermissions;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `{{ enquiry_pressure() }}` fuer die Seitenleiste, `{{ enquiries_of() }}`
 * fuer den Verlauf in den Stammdaten.
 *
 * **Die Vorlagen fragen, nicht die Module.** Die Stammdaten duerfen das
 * Portal nicht kennen — kein Modul haengt vom Portal ab. Zusammengesetzt wird
 * in der Ansicht, genau wie die Seitenleiste `dunning_pressure()` benutzt.
 *
 * Beide antworten leer, wo das Recht fehlt: eine Zahl, die nirgends
 * erscheint, kostet sonst trotzdem eine Abfrage — und ein Verlauf, den
 * jemand nicht oeffnen darf, soll auch nicht als Liste dastehen.
 */
final class EnquiryBadge extends AbstractExtension
{
    public function __construct(
        private readonly EnquiryPressure $pressure,
        private readonly EnquiryRepository $enquiries,
        private readonly AuthorizationCheckerInterface $mayView,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('enquiry_pressure', $this->unread(...)),
            new TwigFunction('enquiries_of', $this->ofParty(...)),
        ];
    }

    public function unread(): int
    {
        return $this->mayView->isGranted(PortalPermissions::VIEW) ? $this->pressure->unread() : 0;
    }

    /**
     * @return list<EnquiryTrace>
     */
    public function ofParty(string $partyId): array
    {
        if (!$this->mayView->isGranted(PortalPermissions::VIEW)) {
            return [];
        }

        return array_map(
            static fn (Enquiry $enquiry): EnquiryTrace => new EnquiryTrace(
                $enquiry->id(),
                $enquiry->number(),
                $enquiry->subject(),
                $enquiry->createdAt(),
                $enquiry->state()->labelKey(),
            ),
            $this->enquiries->forParty($partyId),
        );
    }
}
