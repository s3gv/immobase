<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Domain;

use DateTimeImmutable;

interface TokenRepository
{
    /**
     * Stellt einen Schluessel aus und entwertet dabei die offenen desselben
     * Zwecks — beides zusammen oder gar nicht.
     *
     * Getrennt ausgefuehrt koennten zwei gleichzeitige Anforderungen beide
     * entwerten und danach beide einen Schluessel hinterlassen: zwei gueltige
     * Links, wo einer gemeint war.
     *
     * Die Umsetzung muss dafuer auch dann serialisieren, wenn es noch gar
     * keinen offenen Schluessel gibt — sonst ist genau die erste Anforderung
     * ungeschuetzt.
     */
    public function issue(SignInToken $token, DateTimeImmutable $now): void;

    /**
     * Sucht ueber den Hash des Klartextes.
     *
     * Der Klartext selbst kommt nie in die Naehe der Datenbank — die Suche
     * findet nur, wer den Schluessel wirklich hat.
     */
    public function findUsable(string $plain, TokenPurpose $purpose, DateTimeImmutable $now): ?SignInToken;

    /**
     * Entwertet einen Schluessel und sagt, ob dieser Aufruf ihn erwischt hat.
     *
     * Der Rueckgabewert ist der Kern: gepruefte und entwertete Verwendung
     * finden in einer einzigen bedingten Abfrage statt. Zwei gleichzeitige
     * Anfragen mit demselben Link bekommen daher nicht zweimal true —
     * "einmal verwendbar" waere sonst eine Absichtserklaerung und keine
     * Zusicherung.
     */
    public function consume(SignInToken $token, DateTimeImmutable $now): bool;

    /**
     * Entwertet alle offenen Schluessel eines Zwecks fuer ein Konto.
     *
     * Nach einem neuen Passwort: ein Link zum Zuruecksetzen, der irgendwo
     * mitgelesen wurde, darf danach nichts mehr bewirken.
     */
    public function revokeOpen(string $userId, TokenPurpose $purpose, DateTimeImmutable $now): void;

    public function findOpenInvite(string $userId, DateTimeImmutable $now): ?SignInToken;

    /** @return int Anzahl entfernter Schluessel */
    public function prune(DateTimeImmutable $before): int;
}
