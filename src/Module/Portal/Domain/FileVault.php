<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\Domain;

/**
 * Versiegelt und oeffnet Dateien.
 *
 * Eine Schnittstelle und kein Aufruf der Krypto-Bibliothek mitten im Ablauf:
 * ob eine Installation einen Schluessel hat, ist eine Frage des Betriebs und
 * gehoert nicht in den Anwendungsfall, der eine Nachricht schreibt.
 */
interface FileVault
{
    /**
     * Hat diese Installation ueberhaupt einen Schluessel?
     *
     * Ohne ihn wird **nicht hochgeladen**, statt still im Klartext abzulegen.
     * Ein Betreiber, der die Umgebung unvollstaendig eingerichtet hat, soll
     * es merken, bevor die ersten Belege liegen — nicht danach.
     */
    public function isReady(): bool;

    /**
     * @param string $id die Dateikennung — sie geht als zusaetzliche Daten in
     *                   die Verschluesselung ein, damit sich ein Geheimtext
     *                   nicht in eine andere Zeile umhaengen laesst
     *
     * @throws FileVaultIsNotReady
     */
    public function seal(string $plain, string $id): SealedFile;

    /**
     * Zurueck in Klartext — oder null, wenn es nicht aufgeht.
     *
     * Null statt einer Ausnahme: ein Anhang, der sich nicht oeffnen laesst
     * (vertauschter Schluessel, veraenderte Zeile), ist ein Fall fuer eine
     * Meldung auf dem Blatt und nicht fuer einen Absturz der Seite.
     */
    public function open(SealedFile $sealed, string $id): ?string;
}
