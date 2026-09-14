<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\Application;

use App\Module\Portal\Domain\Enquiry;
use App\Module\Portal\Domain\EnquiryRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Die Anfragen des Angemeldeten — und nur seine.
 *
 * Jede Kennung aus der Adresszeile wird hier gegen die Partei aus der Sitzung
 * gehalten, **bevor** etwas geladen wird. Eine fremde Anfrage gibt es fuer
 * dieses Konto nicht — nicht „verboten", sondern nicht vorhanden: was es
 * nicht sehen darf, soll es auch nicht erfragen koennen.
 */
final readonly class MyEnquiries
{
    public function __construct(
        private PortalIdentity $who,
        private EnquiryRepository $enquiries,
    ) {
    }

    /**
     * Fuer wen der Angemeldete spricht.
     *
     * Die eine Stelle, an der die Kennung das Portal verlaesst — beim
     * Anlegen einer Anfrage, die ja noch keine Partei hat, an der man sie
     * pruefen koennte. Ueberall sonst wird nicht gefragt, wem etwas gehoert,
     * sondern nur nach dem Eigenen.
     */
    public function partyId(): string
    {
        return $this->who->partyId();
    }

    /** @return list<Enquiry> */
    public function all(): array
    {
        return $this->enquiries->forParty($this->who->partyId());
    }

    /**
     * Eine eigene Anfrage — oder 404.
     *
     * @throws NotFoundHttpException
     */
    public function mine(string $id): Enquiry
    {
        $enquiry = $this->enquiries->byId($id);

        if (null === $enquiry || !$this->who->owns($enquiry->partyId())) {
            throw new NotFoundHttpException('Diese Anfrage gibt es nicht.');
        }

        return $enquiry;
    }

    public function unread(): int
    {
        $unread = 0;

        foreach ($this->all() as $enquiry) {
            $unread += $enquiry->isUnreadByParty() ? 1 : 0;
        }

        return $unread;
    }
}
