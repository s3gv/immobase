<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Twig;

use App\Module\Billing\Application\CheckCorrections;
use App\Module\Billing\Application\CheckPlanCorrections;
use Symfony\Component\HttpFoundation\Exception\SessionNotFoundException;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Die Zahl am Menuepunkt — gezaehlt, wenn es sich lohnt.
 *
 * Ob eine Korrektur faellig ist, laesst sich nur herausfinden, indem jede
 * freigegebene Abrechnung neu gerechnet und mit ihrem eingefrorenen Stand
 * verglichen wird. Das ist zu viel Arbeit fuer jeden Seitenaufruf und zu
 * wenig, um sie zu scheuen, wenn jemand hinsieht.
 *
 * Gezaehlt wird darum an drei Stellen: bei der Anmeldung, beim Oeffnen der
 * Abrechnungsuebersicht — die rechnet ohnehin — und auf Knopfdruck. Zwischen
 * diesen Zeitpunkten steht die Zahl in der Sitzung. Sie ist damit nicht
 * minutenaktuell, und das muss sie nicht sein: eine faellige Korrektur wird
 * nicht dringender, weil sie vor drei Minuten entstanden ist. Die Frist
 * laeuft in Monaten.
 *
 * **Die Zahl ist nie die Wahrheit, sondern ihr letzter bekannter Stand.** Die
 * Wahrheit steht auf der Uebersicht, und die rechnet beim Betrachten.
 */
final readonly class CorrectionBadge
{
    private const string IN_SESSION = 'billing.corrections';

    public function __construct(
        private RequestStack $requests,
        private CheckCorrections $corrections,
        private CheckPlanCorrections $plans,
    ) {
    }

    /** Der letzte bekannte Stand; ohne Sitzung: nichts zu melden. */
    public function count(): int
    {
        try {
            $known = $this->requests->getSession()->get(self::IN_SESSION);
        } catch (SessionNotFoundException) {
            return 0;
        }

        return \is_int($known) ? $known : 0;
    }

    /**
     * Neu zaehlen und merken.
     *
     * Abrechnungen und Wirtschaftsplaene zusammen: eine Zahl am Menuepunkt,
     * die die Haelfte der faelligen Korrekturen verschwiege, waere schlimmer
     * als keine.
     */
    public function refresh(): int
    {
        return $this->remember(
            \count($this->corrections->pending()) + \count($this->plans->pending()),
        );
    }

    /**
     * Eine schon gezaehlte Zahl uebernehmen.
     *
     * Die Uebersicht hat die Arbeit beim Zeichnen ohnehin getan; sie ein
     * zweites Mal zu tun waere zweimal dieselbe Frage an dieselbe Datenbank.
     */
    public function remember(int $pending): int
    {
        try {
            $this->requests->getSession()->set(self::IN_SESSION, $pending);
        } catch (SessionNotFoundException) {
            // Ohne Sitzung gibt es nichts zu merken — und niemanden, dem die
            // Zahl gezeigt wuerde.
        }

        return $pending;
    }
}
