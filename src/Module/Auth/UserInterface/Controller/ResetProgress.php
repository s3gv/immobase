<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\UserInterface\Controller;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Merkt sich, dass in *diesem* Durchlauf schon ein neues Passwort gesetzt
 * wurde.
 *
 * Beim Einrichten eines Kontos beantwortet das Konto selbst die Frage: wer
 * kein Passwort hat, steht beim Passwort. Beim Zuruecksetzen geht das nicht —
 * dort gibt es eines, und trotzdem ist es der erste Schritt.
 *
 * In der Sitzung und nicht am Konto: geht sie verloren, ist das Passwort
 * trotzdem gesetzt, und nur die Angaben danach bleiben liegen — die lassen
 * sich nach der Anmeldung im Konto nachtragen. Ein Vermerk am Konto muesste
 * dagegen wieder aufgeraeumt werden.
 */
final readonly class ResetProgress
{
    private const string KEY = 'auth.reset_progress';

    public function __construct(private RequestStack $requests)
    {
    }

    public function passwordIsSet(string $token): bool
    {
        return null !== $this->accountOf($token);
    }

    /**
     * Wessen Passwort in diesem Durchlauf gesetzt wurde.
     *
     * Der Schluessel selbst ist dann schon verbraucht — das neue Passwort
     * entwertet ihn. Was danach noch kommt, traegt die Sitzung, die es
     * gesetzt hat, und kein Link, den jemand anders auch haben koennte.
     */
    public function accountOf(string $token): ?string
    {
        $remembered = $this->requests->getSession()->get(self::KEY);

        return \is_array($remembered) && ($remembered['token'] ?? null) === $this->fingerprint($token) && \is_string($remembered['user'] ?? null)
            ? $remembered['user']
            : null;
    }

    public function rememberPassword(string $token, string $userId): void
    {
        $this->requests->getSession()->set(self::KEY, ['token' => $this->fingerprint($token), 'user' => $userId]);
    }

    public function forget(): void
    {
        $this->requests->getSession()->remove(self::KEY);
    }

    /** Auch hier nicht der Klartext: er soll nirgends liegen bleiben. */
    private function fingerprint(string $token): string
    {
        return hash('sha256', $token);
    }
}
