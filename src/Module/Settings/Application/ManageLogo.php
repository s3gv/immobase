<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Settings\Application;

use App\Module\Settings\Domain\Logo;
use App\Module\Settings\Domain\LogoRejected;
use App\Module\Settings\Domain\LogoRepository;
use DateTimeImmutable;

/**
 * Das Logo hochladen und entfernen.
 *
 * Es gibt hoechstens eines. Ein zweites hochzuladen ersetzt das erste an Ort
 * und Stelle — nicht loeschen und neu anlegen: dazwischen staende die
 * Installation ohne Logo da, und im Fehlerfall bliebe sie es.
 */
final readonly class ManageLogo
{
    public function __construct(private LogoRepository $logos)
    {
    }

    /**
     * @throws LogoRejected
     */
    public function replaceWith(string $bytes, DateTimeImmutable $on): Logo
    {
        $logo = $this->logos->current();

        if (null === $logo) {
            $logo = new Logo($bytes, $on);
        } else {
            $logo->replaceWith($bytes, $on);
        }

        $this->logos->save($logo);

        return $logo;
    }

    /**
     * Entfernen — hier ist es richtig.
     *
     * Ein Logo ist keine Buchung: es steht fuer nichts Vergangenes, und ein
     * Briefkopf ohne Logo ist ein Briefkopf ohne Logo. Was einmal gedruckt
     * wurde, traegt seines ohnehin im PDF.
     */
    public function remove(): void
    {
        $logo = $this->logos->current();

        if (null !== $logo) {
            $this->logos->remove($logo);
        }
    }
}
