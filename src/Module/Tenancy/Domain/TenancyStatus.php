<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Domain;

/**
 * Drei Zustaende, und jeder beantwortet eine andere Frage.
 *
 * Vorher waren es zwei, und „inaktiv" musste fuer zweierlei herhalten: ein
 * frisch angelegtes Mietverhaeltnis war inaktiv, ein beendetes auch. Das eine
 * ist angefangene Arbeit, das andere ist Geschichte — und solange beide
 * denselben Zustand tragen, gilt jede Regel fuer beide. Entweder ist der
 * Entwurf unloeschbar oder die Geschichte loeschbar.
 *
 * * **Entwurf** — angelegt, noch nicht in Kraft. Blockiert die Einheit nicht,
 *   taucht in keiner Belegung auf, laesst sich loeschen. So laesst sich der
 *   Nachmieter erfassen, waehrend der Vormieter noch wohnt.
 * * **Aktiv** — laeuft. Die Einheit ist vermietet.
 * * **Beendet** — war, mit Mietende. Wird nicht mehr geloescht: abgerechnet
 *   wird das Vorjahr, manchmal das vorletzte, und dafuer braucht es den
 *   damaligen Stand.
 *
 * Leerstand ist keiner davon: er wird nicht erfasst, sondern erschlossen —
 * eine Einheit ohne aktives Mietverhaeltnis steht leer. Ein Zustand „leer"
 * waere eine zweite Wahrheit neben der Zuordnung selbst.
 */
enum TenancyStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Ended = 'ended';

    /**
     * Aktiv setzen heisst: die Einheit ist vermietet, und zwar an jemanden.
     *
     * Auch das Zuruecknehmen einer versehentlichen Beendigung laeuft hier
     * durch: es ist derselbe Schritt und verlangt dasselbe.
     *
     * Die Regel steht am Zustand und nicht am Mietverhaeltnis, weil sie eine
     * Regel **ueber den Zustand** ist — wer sie dort sucht, findet sie.
     *
     * @throws TenancyNeedsATenant
     */
    public static function activeWith(int $tenants): self
    {
        if (0 === $tenants) {
            throw new TenancyNeedsATenant();
        }

        return self::Active;
    }

    public function labelKey(): string
    {
        return 'tenancy.status.'.$this->value;
    }

    public function isActive(): bool
    {
        return self::Active === $this;
    }

    public function isDraft(): bool
    {
        return self::Draft === $this;
    }

    /** Vergangenes — wird in Listen nur auf Wunsch gezeigt. */
    public function isPast(): bool
    {
        return self::Ended === $this;
    }
}
