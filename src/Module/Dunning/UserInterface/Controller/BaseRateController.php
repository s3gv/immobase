<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\UserInterface\Controller;

use App\Module\Dunning\Domain\BaseRate;
use App\Module\Dunning\Domain\BaseRateRepository;
use App\Module\Dunning\Domain\DunningPermissions;
use App\Shared\Number\Decimals;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Die Basiszinssaetze pflegen.
 *
 * Zweimal im Jahr eine Zeile — zum 1. Januar und zum 1. Juli, und nur, wenn
 * sich etwas geaendert hat. Die Seite sagt es, wenn der Satz fuer das
 * laufende Halbjahr fehlt: ohne ihn rechnete die Anwendung mit einem
 * veralteten weiter, und das faellt erst vor Gericht auf.
 */
#[IsGranted(DunningPermissions::VIEW)]
final class BaseRateController extends AbstractController
{
    public function __construct(
        private readonly BaseRateRepository $rates,
        private readonly DunningPage $page,
    ) {
    }

    #[Route('/finanzen/mahnwesen/basiszinssaetze', name: 'app_dunning_rate', methods: ['GET'])]
    public function index(): Response
    {
        $rates = $this->rates->all();

        return $this->render('dunning/basiszinssaetze.html.twig', [
            'rates' => $rates,
            'latest' => $rates->latest(),
            'missing' => $rates->missingFor(new DateTimeImmutable('today')),
            'trail' => $this->page->trail('dunning.rate.heading'),
        ]);
    }

    #[IsGranted(DunningPermissions::EDIT)]
    #[Route('/finanzen/mahnwesen/basiszinssaetze/eintragen', name: 'app_dunning_rate_add', methods: ['POST'])]
    public function add(Request $request): Response
    {
        $this->guard($request);
        $from = $request->request->getString('validFrom');
        $day = DateTimeImmutable::createFromFormat('Y-m-d', $from);

        if (false === $day || $day->format('Y-m-d') !== $from) {
            return $this->redirectToRoute('app_dunning_rate');
        }

        $this->rates->save(new BaseRate($day->setTime(0, 0), self::basisPointsOf($request->request->getString('rate'))));
        $this->addFlash('success', 'dunning.rate.added');

        return $this->redirectToRoute('app_dunning_rate');
    }

    #[IsGranted(DunningPermissions::EDIT)]
    #[Route(
        '/finanzen/mahnwesen/basiszinssaetze/{id}/entfernen',
        name: 'app_dunning_rate_remove',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    public function remove(string $id, Request $request): Response
    {
        $this->guard($request);
        $rate = $this->rates->byId($id) ?? throw new NotFoundHttpException('Diesen Satz gibt es nicht.');
        $this->rates->remove($rate);
        $this->addFlash('success', 'dunning.rate.removed');

        return $this->redirectToRoute('app_dunning_rate');
    }

    /**
     * „1,52" wird zu 152 — und „−0,88" zu −88.
     *
     * Ganzzahlig und ohne Fliesskomma: ein Zinssatz ist hier eine Anzahl
     * Basispunkte, und der Umweg ueber eine Gleitkommazahl brauchte es nur,
     * um sie wieder zu verlieren.
     *
     * Das Vorzeichen muss mit — auch das typografische Minus, das aus einer
     * kopierten Tabelle kommt. Von Mitte 2016 bis Ende 2022 war der Satz
     * negativ, und ein Leser, der das verschluckt, rechnet sechs Jahre lang
     * falsch herum.
     */
    private static function basisPointsOf(string $input): int
    {
        $normalised = str_replace([' ', '−', '%'], ['', '-', ''], trim($input));
        $negative = str_starts_with($normalised, '-');
        $parts = explode('.', Decimals::normalise(ltrim($normalised, '+-')), 2);
        $fraction = $parts[1] ?? '';
        $points = ((int) $parts[0]) * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');

        return $negative ? -$points : $points;
    }

    private function guard(Request $request): void
    {
        if (!$this->isCsrfTokenValid('dunning', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }
    }
}
