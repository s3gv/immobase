<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Application;

use App\Module\Auth\Domain\User;

/**
 * Was dieses Modul an Menschen verschickt.
 *
 * Eine Schnittstelle und keine direkte Benutzung des Mailers: ob eine
 * Nachricht wirklich hinausgeht, haengt davon ab, ob ein Mailserver
 * eingerichtet ist. Diese Frage gehoert nicht in den Ablauf, der einlaedt.
 */
interface Notifier
{
    public function invite(User $user, string $link): void;

    public function resetPassword(User $user, string $link): void;

    public function secondFactorCode(User $user, string $code): void;

    public function confirmEmailChange(User $user, string $newAddress, string $link): void;

    /** Ohne Link — reine Benachrichtigung, damit ein stiller Wechsel auffaellt. */
    public function passwordChanged(User $user): void;

    public function emailChanged(User $user, string $previousAddress, string $newAddress): void;

    /** Verschickt diese Installation ueberhaupt E-Mails? */
    public function isConfigured(): bool;
}
