<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\Infrastructure;

use App\Module\Portal\Application\Notifier;
use App\Module\Portal\Domain\Enquiry;
use App\Shared\Http\PublicUrls;
use App\Shared\Mail\OutgoingMail;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;

/**
 * Die eine Mail, die das Portal verschickt.
 *
 * **Ohne Inhalt.** Sie nennt den Betreff der Anfrage und einen Link zur
 * Anmeldung — keinen Ausschnitt, keinen Anhang, keinen Namen des Absenders.
 * Eine Mail ist unverschluesselte Post, sie liegt auf fremden Servern und in
 * Postfaechern, die andere mitlesen; was im Portal steht, bleibt im Portal.
 *
 * Der Betreff der Anfrage steht drin, weil sonst niemand weiss, worum es
 * geht, und drei solcher Mails ununterscheidbar waeren. Ihn hat der Empfaenger
 * ohnehin selbst geschrieben.
 */
final readonly class MailNotifier implements Notifier
{
    public function __construct(
        private OutgoingMail $mail,
        private PublicUrls $urls,
    ) {
    }

    public function somethingIsNew(Enquiry $enquiry, string $to): void
    {
        $this->mail->queue(
            (new TemplatedEmail())
                ->subject($this->mail->subject('email.portal_message.subject'))
                ->textTemplate('email/portal_message.txt.twig')
                ->htmlTemplate('email/portal_message.html.twig')
                ->context([
                    'subject' => $enquiry->subject(),
                    'link' => $this->urls->absolute('app_portal_enquiry', ['id' => $enquiry->id()]),
                ])
                ->to($to),
        );
    }

    public function isConfigured(): bool
    {
        return $this->mail->isConfigured();
    }
}
