<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Audit\Infrastructure;

use App\Module\Audit\Application\WhoActed;
use App\Module\Audit\Domain\AuditEntry;
use App\Module\Audit\Domain\AuditRepository;
use App\Shared\Audit\AuditAction;
use App\Shared\Write\DescribesRecords;
use App\Shared\Write\ObservesWrites;
use App\Shared\Write\WriteHappened;

/**
 * Jedes Speichern und jedes Loeschen steht danach im Protokoll.
 *
 * **Ein Beobachter und kein Lauscher.** Das Signal kommt aus
 * {@see \App\Shared\Write\WatchesEveryWrite} und wird geteilt: das Protokoll
 * hoert darauf, die Zustellung an Plugins ebenso. Kein Modul haengt am
 * Protokoll, und das Protokoll haengt an keinem Modul.
 *
 * **Geschrieben wird ueber einfache INSERTs auf derselben Verbindung** und
 * nicht ueber die Arbeitseinheit — siehe {@see DoctrineAuditRepository::append()}.
 * Damit kann das Protokoll den Vorgang nicht stoeren, den es festhaelt, und
 * es kann sich auch nicht selbst protokollieren: was nie durch die
 * Arbeitseinheit geht, loest kein `postPersist` aus.
 */
final readonly class RecordsEveryWrite implements ObservesWrites
{
    public function __construct(
        private AuditRepository $entries,
        private WhoActed $who,
        private DescribesRecords $describe,
    ) {
    }

    public function saw(array $writes): void
    {
        [$actor, $kind] = $this->who->now();

        $this->entries->append(array_map(
            fn (WriteHappened $write): AuditEntry => new AuditEntry(
                $write->at,
                self::actionOf($write),
                $actor,
                $kind,
                $this->describe->kindOf($write->entity),
                $this->describe->idOf($write->entity),
                $this->describe->labelOf($write->entity),
            ),
            $writes,
        ));
    }

    private static function actionOf(WriteHappened $write): AuditAction
    {
        return match ($write->action) {
            WriteHappened::CREATED => AuditAction::Created,
            WriteHappened::DELETED => AuditAction::Deleted,
            default => AuditAction::Updated,
        };
    }
}
