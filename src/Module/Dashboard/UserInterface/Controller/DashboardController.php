<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dashboard\UserInterface\Controller;

use App\Module\Dashboard\Application\CollectsFigures;
use App\Module\Dashboard\Application\CollectsTodos;
use App\Module\Dashboard\Application\DrawsTheYear;
use App\Module\Dashboard\Application\WhoIsHere;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Einstiegspunkt nach der Anmeldung.
 *
 * Ganz oben eine Begruessung und die Uhr, darunter das Jahr als Balken mit
 * allem, was darauf faellig ist. Dann, was auf einen Menschen wartet — aus
 * allen Modulen, mit dem Knopf, mit dem man es erledigt. Zuletzt die Zahlen,
 * mit denen man den Tag anfaengt.
 *
 * Die Uebersicht kennt dabei kein Fachmodul: sie fragt, wer sich gemeldet
 * hat, und stellt es dar. Rechte prueft sie nicht — das tut jede Quelle fuer
 * sich, und eine Stelle, die alle Rechte aller Module kennt, waere die, an
 * der eines vergessen wird.
 */
final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly CollectsTodos $todos,
        private readonly CollectsFigures $figures,
        private readonly DrawsTheYear $year,
        private readonly WhoIsHere $who,
    ) {
    }

    #[Route('/', name: 'app_dashboard', methods: ['GET'])]
    public function index(): Response
    {
        $timeline = $this->year->forThisYear();

        return $this->render('dashboard/index.html.twig', [
            'me' => ($this->who)(),
            'greeting' => self::greetingAt($timeline->today),
            'timeline' => $timeline,
            'groups' => $this->todos->groups(),
            'sections' => $this->figures->sections(),
            'trail' => [
                ['label' => 'ImmoBase', 'url' => null],
            ],
        ]);
    }

    /**
     * Morgen, Tag oder Abend.
     *
     * Die Grenzen sind grob und sollen es sein: „Guten Morgen" um 11:59 ist
     * ein Fehler, um 10:45 nicht. Wer nachts um drei arbeitet, bekommt den
     * Morgen — das ist freundlicher als die Wahrheit.
     */
    private static function greetingAt(DateTimeImmutable $now): string
    {
        $hour = (int) $now->format('G');

        return match (true) {
            $hour < 11 => 'morning',
            $hour < 18 => 'day',
            default => 'evening',
        };
    }
}
