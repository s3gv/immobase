<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\Application;

use App\Module\Portal\Domain\EnquiryRepository;

/**
 * Die Zahl am Menuepunkt „Anfragen".
 *
 * Ungelesene, nicht offene: das Abzeichen sagt „hier hat noch niemand
 * hingesehen" und nicht „hier ist noch etwas zu tun". Eine Anfrage, die
 * gelesen und in Arbeit ist, gehoert nicht mehr in eine rote Zahl.
 */
final readonly class EnquiryPressure
{
    public function __construct(private EnquiryRepository $enquiries)
    {
    }

    public function unread(): int
    {
        return $this->enquiries->countUnreadByStaff();
    }
}
