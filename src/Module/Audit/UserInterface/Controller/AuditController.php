<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Audit\UserInterface\Controller;

use App\Module\Audit\Application\LooksAtTheTrail;
use App\Module\Audit\Domain\AuditFilter;
use App\Module\Audit\Domain\AuditPermissions;
use App\Module\Audit\UserInterface\Pdf\TrailSheet;
use App\Shared\Audit\AuditAction;
use App\Shared\Http\FormInput;
use App\Shared\Ui\Page;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Das Aenderungsprotokoll.
 *
 * Lesen und ausdrucken, mehr gibt es hier nicht. Ein Protokoll, das sich
 * bearbeiten laesst, beantwortet die Frage nicht mehr, fuer die es da ist —
 * und geloescht wird es von selbst.
 */
#[IsGranted(AuditPermissions::VIEW)]
final class AuditController extends AbstractController
{
    public function __construct(
        private readonly LooksAtTheTrail $trail,
        private readonly TrailSheet $sheet,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/protokoll', name: 'app_audit', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filter = self::filterFrom($request);
        $page = Page::of(FormInput::queryIntOrNull($request, 'page') ?? 1, $this->trail->count($filter));

        return $this->render('audit/index.html.twig', [
            'lines' => $this->trail->page($filter, $page),
            'page' => $page,
            'filter' => $filter,
            'actions' => AuditAction::cases(),
            'url' => self::listUrl($request),
            'trail' => [
                ['label' => 'ImmoBase', 'url' => $this->generateUrl('app_dashboard')],
                ['label' => $this->translator->trans('audit.heading'), 'url' => null],
            ],
        ]);
    }

    /**
     * Der Abzug der achtundvierzig Stunden.
     *
     * Ungefiltert und vollstaendig: ein Abzug, der nur zeigt, wonach jemand
     * gerade gesucht hat, ist kein Abzug. Was aufbewahrt werden soll, wird so
     * aufbewahrt — danach raeumt der Aufraeumer.
     */
    #[Route('/protokoll/pdf', name: 'app_audit_pdf', methods: ['GET'])]
    public function sheet(): Response
    {
        return new Response(
            ($this->sheet)($this->trail->everything()),
            Response::HTTP_OK,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="protokoll.pdf"',
            ],
        );
    }

    private static function filterFrom(Request $request): AuditFilter
    {
        return AuditFilter::of($request->query->getString('aktion'), $request->query->getString('q'));
    }

    private static function listUrl(Request $request): string
    {
        $query = array_filter([
            'aktion' => $request->query->getString('aktion'),
            'q' => $request->query->getString('q'),
            'page' => '__PAGE__',
        ], static fn (string $value): bool => '' !== $value);

        return '/protokoll?'.http_build_query($query);
    }
}
