<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\UserInterface\Controller;

use App\Module\Portal\Domain\Enquiry;
use App\Module\Portal\Domain\EnquiryRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Die Anfrage zur Adresse — oder 404.
 *
 * Nur fuer den Verwalterbereich: dort sieht jeder mit dem Recht alle
 * Anfragen. Im Portal geht derselbe Weg ueber {@see \App\Module\Portal\Application\MyEnquiries},
 * und das ist kein doppelter Code, sondern eine andere Frage — dort ist
 * „gehoert mir das" die eigentliche Pruefung.
 */
final readonly class RequireEnquiry
{
    public function __construct(private EnquiryRepository $enquiries)
    {
    }

    public function __invoke(string $id): Enquiry
    {
        return $this->enquiries->byId($id) ?? throw new NotFoundHttpException('Diese Anfrage gibt es nicht.');
    }
}
