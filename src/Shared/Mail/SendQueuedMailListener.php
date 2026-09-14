<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Mail;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Schickt die gesammelten E-Mails los, nachdem die Antwort draussen ist.
 *
 * Auch fuer die Konsole eingehaengt: ein Konsolenbefehl, der einlaedt, hat
 * kein kernel.terminate — deshalb ruft er flush() selbst auf.
 */
#[AsEventListener(event: KernelEvents::TERMINATE, method: 'afterRequest')]
#[AsEventListener(event: ConsoleEvents::TERMINATE, method: 'afterCommand')]
final readonly class SendQueuedMailListener
{
    public function __construct(private OutgoingMail $mail)
    {
    }

    public function afterRequest(TerminateEvent $event): void
    {
        $this->mail->flush();
    }

    /**
     * Ein Konsolenbefehl hat kein kernel.terminate. Ohne diese Zeile bliebe
     * eine dort ausgeloeste E-Mail liegen und niemand erfuehre davon.
     */
    public function afterCommand(ConsoleTerminateEvent $event): void
    {
        $this->mail->flush();
    }
}
