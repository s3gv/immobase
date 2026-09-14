<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Audit\Application;

use App\Module\Audit\Domain\ActorKind;
use App\Module\Auth\Contract\UserDirectory;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Wer gerade handelt — Name und Art, mehr braucht das Protokoll nicht.
 *
 * Der Name wird beim Schreiben eingefroren. Ein Verweis auf das Konto waere
 * kuerzer, aber ein Protokoll, dessen Eintraege unlesbar werden, sobald ein
 * Konto geloescht wird, protokolliert das Gegenteil von dem, wozu es da ist.
 *
 * Ohne Anmeldung ist es das System: Konsolenbefehle, der Aufraeumer, eine
 * Migration. Sie handeln wirklich, und sie zu verschweigen hiesse, eine
 * Aenderung ohne Urheber dastehen zu lassen.
 */
final readonly class WhoActed
{
    public function __construct(
        private Security $security,
        private UserDirectory $users,
    ) {
    }

    /**
     * @return array{string, ActorKind}
     */
    public function now(): array
    {
        $signedIn = $this->security->getUser();

        if (null === $signedIn) {
            return ['System', ActorKind::System];
        }

        $email = $signedIn->getUserIdentifier();
        $known = $this->users->byEmail($email);

        return [$known->displayName ?? $email, self::kindOf($signedIn->getRoles())];
    }

    /**
     * Der volle Name zu einer Adresse — oder die Adresse selbst.
     *
     * Fuer die Anmeldung: dort gibt es die Sitzung noch nicht (oder nicht
     * mehr), und die Adresse ist das Einzige, was in beiden Momenten
     * feststeht. Wo der Name bekannt ist, steht er trotzdem dabei — „Klick
     * Tester" liest sich in einer Liste besser als eine Mailadresse.
     */
    public function nameFor(string $email): string
    {
        return $this->users->byEmail($email)->displayName ?? $email;
    }

    /**
     * Portalkonto oder Verwaltung.
     *
     * Ueber die Rollen und nicht ueber die Entity der Anmeldung: das
     * Protokoll darf sie nicht kennen, und `ROLE_PORTAL` steht ohnehin in
     * jeder Sitzung.
     *
     * @param array<int|string, string> $roles
     */
    private static function kindOf(array $roles): ActorKind
    {
        return \in_array('ROLE_PORTAL', $roles, true) ? ActorKind::Portal : ActorKind::Staff;
    }
}
