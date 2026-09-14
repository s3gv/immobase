<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Application;

/**
 * Was aus einer Aenderung an einem fremden Konto geworden ist.
 *
 * Zwei Auskuenfte in einer, weil sie zusammengehoeren: ob sie stattgefunden
 * hat, und was dem Benutzer dazu zu sagen ist. Getrennt zurueckgegeben
 * verleitet es dazu, das eine zu pruefen und das andere zu vergessen — und
 * eine Erfolgsmeldung nach einer abgelehnten Aenderung ist schlimmer als
 * keine.
 */
final readonly class ChangeOutcome
{
    private function __construct(
        public bool $applied,
        public string $message,
    ) {
    }

    public static function done(string $message): self
    {
        return new self(true, $message);
    }

    public static function refused(string $reason): self
    {
        return new self(false, $reason);
    }

    /** 'success' oder 'error' — der Kanal, in den die Meldung gehoert. */
    public function flash(): string
    {
        return $this->applied ? 'success' : 'error';
    }
}
