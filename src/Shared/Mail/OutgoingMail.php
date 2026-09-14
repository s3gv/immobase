<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Mail;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Sammelt ausgehende E-Mails und verschickt sie nach der Antwort.
 *
 * Der Versand laeuft im kernel.terminate-Ereignis, also nachdem der Browser
 * seine Antwort hat. Zwei Gruende:
 *
 * 1. "Passwort vergessen" darf ueber die Antwortzeit nicht verraten, ob es
 *    das Konto gibt. Ein SMTP-Gespraech dauert deutlich laenger als eine
 *    erfolglose Suche — im Request waere der Unterschied messbar.
 * 2. Ein langsamer oder toter Mailserver soll die Seite nicht aufhalten.
 *
 * Bewusst kein symfony/messenger mit eigenem Transport: das braeuchte einen
 * laufenden Worker-Prozess. Eine selbst gehostete Installation soll fuer
 * ankommende Einladungen keinen zweiten Dienst betreiben muessen.
 */
final class OutgoingMail
{
    /** @var list<Email> */
    private array $queued = [];

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
        private readonly string $dsn,
        private readonly string $from,
    ) {
    }

    /**
     * Ist ein Mailserver eingerichtet?
     *
     * null:// ist Symfonys Transport ins Nichts und zugleich unsere Vorgabe.
     * Wer nichts eingerichtet hat, soll den Einladungslink angezeigt bekommen,
     * statt auf eine E-Mail zu warten, die nie ankommt.
     */
    public function isConfigured(): bool
    {
        return '' !== $this->dsn && !str_starts_with($this->dsn, 'null://');
    }

    public function subject(string $key): string
    {
        return $this->translator->trans($key);
    }

    public function queue(Email $email): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        $this->queued[] = $email->from($this->from);
    }

    public function sender(): string
    {
        return $this->from;
    }

    /**
     * Verschickt, was sich angesammelt hat.
     *
     * Ein Fehler landet im Log und nicht auf einer Fehlerseite: die Antwort
     * ist zu diesem Zeitpunkt laengst beim Browser, und die Handlung selbst —
     * das Einladen, das Zuruecksetzen — hat stattgefunden.
     */
    public function flush(): void
    {
        $queued = $this->queued;
        $this->queued = [];

        foreach ($queued as $email) {
            try {
                $this->mailer->send($email);
            } catch (TransportExceptionInterface $failure) {
                $this->logger->error('E-Mail konnte nicht verschickt werden: {reason}', [
                    'reason' => $failure->getMessage(),
                ]);
            }
        }
    }
}
