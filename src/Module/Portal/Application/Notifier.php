<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\Application;

use App\Module\Portal\Domain\Enquiry;

/**
 * Was das Portal an Menschen verschickt.
 *
 * Genau eine Nachricht, und sie traegt **keinen Inhalt**: dass es zu einer
 * Anfrage etwas Neues gibt, und einen Link zur Anmeldung. Kein Ausschnitt,
 * kein Anhang. Eine Mail ist unverschluesselte Post; was im Portal steht,
 * bleibt im Portal.
 */
interface Notifier
{
    /**
     * @param string $to die Adresse des Portalkontos
     */
    public function somethingIsNew(Enquiry $enquiry, string $to): void;

    /** Verschickt diese Installation ueberhaupt E-Mails? */
    public function isConfigured(): bool;
}
