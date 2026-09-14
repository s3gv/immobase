<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\Application;

use App\Module\Auth\Contract\PortalAccounts;
use App\Module\Portal\Domain\AttachmentRepository;
use App\Module\Portal\Domain\EnquiryRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * Die Zeitarbeit des Portals: abgelaufene Dateien loeschen, faellige Mails
 * verschicken.
 *
 * **Ausdruecklich ein Aufruf und kein Aufraeumen nebenbei.** Was still beim
 * naechsten Seitenaufruf passiert, passiert genau dann nicht, wenn niemand
 * die Seite aufruft — und an einem Freitagabend ruft niemand auf.
 *
 * Laeuft der Lauf nicht, ist trotzdem nichts kaputt: eine abgelaufene Datei
 * wird ohnehin nicht mehr ausgeliefert, und die Mails kommen verspaetet statt
 * gar nicht. Das ist der Unterschied zwischen einem Versprechen, das an einem
 * Zeitplan haengt, und einem, das ihn nur benutzt.
 */
final readonly class SweepThePortal
{
    public function __construct(
        private AttachmentRepository $attachments,
        private EnquiryRepository $enquiries,
        private PortalAccounts $accounts,
        private Notifier $notifier,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{forgotten: int, notified: int}
     */
    public function __invoke(): array
    {
        $now = $this->clock->now();

        return [
            'forgotten' => $this->attachments->forgetExpired($now),
            'notified' => $this->notifyWhatIsDue(),
        ];
    }

    /**
     * Die faelligen Benachrichtigungen — hoechstens eine je Anfrage.
     *
     * Der Speicher liefert nur, was faellig **und** weiterhin ungelesen ist;
     * wer zwischenzeitlich gelesen hat, hat den Termin damit selbst
     * geloescht. Danach wird vermerkt, dass benachrichtigt wurde, und bis zum
     * naechsten Lesen kommt keine zweite.
     */
    private function notifyWhatIsDue(): int
    {
        $now = $this->clock->now();
        $sent = 0;

        foreach ($this->enquiries->withNotificationDue($now) as $enquiry) {
            $account = $this->accounts->forParty($enquiry->partyId());

            // Kein Zugang mehr, keine Mail — aber der Termin verfaellt, sonst
            // sieht der Lauf ihn bei jedem Durchgang wieder.
            if (null !== $account && $account->canSignIn) {
                $this->notifier->somethingIsNew($enquiry, $account->email);
                ++$sent;
            }

            $enquiry->notificationSentAt($now);
            $this->enquiries->save($enquiry);
        }

        return $sent;
    }
}
