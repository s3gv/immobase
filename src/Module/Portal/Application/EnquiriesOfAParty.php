<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\Application;

use App\Module\Auth\Contract\PortalAccounts;
use App\Module\Party\Contract\PartyBelongings;
use App\Module\Portal\Domain\EnquiryRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Was das Portal an einer Partei haengen hat — und was damit verschwindet.
 *
 * Gespraeche, Nachrichten, Anhaenge und der Portalzugang. Ein Gespraech ohne
 * Gegenueber ist Aktenmuell, und ein Konto, das fuer niemanden mehr spricht,
 * darf sich nicht anmelden koennen.
 *
 * Die Richtung ist die von {@see PartyBelongings}: die Stammdaten kennen das
 * Portal nicht, das Portal kennt die Stammdaten.
 */
final readonly class EnquiriesOfAParty implements PartyBelongings
{
    public function __construct(
        private EnquiryRepository $enquiries,
        private PortalAccounts $accounts,
        private TranslatorInterface $translator,
    ) {
    }

    public function discardFor(array $partyIds): void
    {
        foreach ($partyIds as $partyId) {
            $this->enquiries->discardFor($partyId);
            $this->accounts->removeFor($partyId);
        }
    }

    public function announceFor(array $partyIds): array
    {
        $announced = [];

        foreach ($partyIds as $partyId) {
            $lines = [];
            $enquiries = \count($this->enquiries->forParty($partyId));

            if ($enquiries > 0) {
                $lines[] = $this->translator->trans('portal.delete.enquiries', ['%count%' => $enquiries]);
            }

            if (null !== $this->accounts->forParty($partyId)) {
                $lines[] = $this->translator->trans('portal.delete.account');
            }

            if ([] !== $lines) {
                $announced[$partyId] = $lines;
            }
        }

        return $announced;
    }
}
