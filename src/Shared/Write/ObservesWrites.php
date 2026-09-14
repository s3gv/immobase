<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Write;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Wer davon erfaehrt, dass geschrieben wurde.
 *
 * Zwei Abnehmer: das Aenderungsprotokoll und die Zustellung an Plugins.
 * **Keiner von beiden haengt am anderen** — und kein Fachmodul haengt an
 * einem von beiden. Das Signal kommt von Doctrine, nicht von einem Modul.
 *
 * **Gesammelt und nach dem Speichern uebergeben.** Waehrend eines `flush()`
 * laesst sich nichts anlegen, ohne die Arbeitseinheit durcheinanderzubringen.
 * Ein Beobachter schreibt deshalb ueber einfache Anweisungen auf derselben
 * Verbindung und nicht ueber die Arbeitseinheit — sonst protokollierte er
 * sich beim naechsten Mal selbst.
 */
#[AutoconfigureTag('write.observer')]
interface ObservesWrites
{
    /**
     * @param non-empty-list<WriteHappened> $writes
     */
    public function saw(array $writes): void;
}
