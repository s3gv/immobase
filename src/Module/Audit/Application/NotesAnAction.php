<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Audit\Application;

use App\Module\Audit\Domain\AuditEntry;
use App\Module\Audit\Domain\AuditRepository;
use App\Shared\Audit\AuditAction;
use App\Shared\Audit\RecordsActions;
use Symfony\Component\Clock\ClockInterface;

/**
 * Nimmt eine gemeldete Zeile entgegen — fuer alles, was nicht durch die ORM
 * geht.
 *
 * Wer handelt, wird hier bestimmt und nicht vom Melder mitgegeben: sonst
 * koennte eine Zeile einen fremden Namen tragen, und ein Protokoll, in dem
 * das moeglich ist, beantwortet seine eigene Frage nicht mehr.
 */
final readonly class NotesAnAction implements RecordsActions
{
    public function __construct(
        private AuditRepository $entries,
        private WhoActed $who,
        private ClockInterface $clock,
    ) {
    }

    public function note(AuditAction $action, string $record, string $recordId, string $label = ''): void
    {
        [$actor, $kind] = $this->who->now();

        $this->entries->append([
            new AuditEntry($this->clock->now(), $action, $actor, $kind, $record, $recordId, $label),
        ]);
    }
}
