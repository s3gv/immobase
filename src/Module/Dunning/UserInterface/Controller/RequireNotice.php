<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\UserInterface\Controller;

use App\Module\Dunning\Domain\Notice;
use App\Module\Dunning\Domain\NoticeRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Das Schreiben zur Adresse — oder 404. */
final readonly class RequireNotice
{
    public function __construct(private NoticeRepository $notices)
    {
    }

    public function __invoke(string $id): Notice
    {
        return $this->notices->byId($id) ?? throw new NotFoundHttpException('Dieses Schreiben gibt es nicht.');
    }
}
