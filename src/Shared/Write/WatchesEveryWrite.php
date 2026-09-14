<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Write;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Jedes Speichern und jedes Loeschen, ohne dass ein Modul etwas dafuer tut.
 *
 * **An Doctrine und nicht am Ereignisbus.** Ein Signal, das auf
 * Fachereignisse hoert, meldet die Faelle, an die beim Bauen jemand gedacht
 * hat — und keinen einzigen mehr. Am Lebenszyklus haengend sieht es jedes
 * `persist()` und jedes `remove()`.
 *
 * **Was es nicht sieht:** ein `DELETE` oder `INSERT` ueber DBAL und eine
 * Massenanweisung in DQL. Beide loesen keinen Lebenszyklus aus. Wer so
 * schreibt, meldet sich ueber {@see \App\Shared\Audit\RecordsActions} — so
 * machen es die Zuordnungstabellen der Rechte. Was die Aufraeumer wegwerfen,
 * weil eine Frist abgelaufen ist, bleibt bewusst draussen: das ist keine
 * Handlung eines Menschen.
 *
 * **Gesammelt und danach uebergeben.** Die Beobachter kommen in `postFlush`
 * zum Zug, wenn der Vorgang durch ist; sie koennen ihn damit nicht mehr
 * stoeren.
 */
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::postRemove)]
#[AsDoctrineListener(event: Events::postFlush)]
final class WatchesEveryWrite
{
    /** @var list<WriteHappened> */
    private array $waiting = [];

    /**
     * @param iterable<ObservesWrites> $observers
     */
    public function __construct(
        #[AutowireIterator('write.observer')]
        private readonly iterable $observers,
        private readonly ClockInterface $clock,
    ) {
    }

    public function postPersist(PostPersistEventArgs $event): void
    {
        $this->note($event->getObject(), WriteHappened::CREATED);
    }

    public function postUpdate(PostUpdateEventArgs $event): void
    {
        $this->note($event->getObject(), WriteHappened::UPDATED);
    }

    public function postRemove(PostRemoveEventArgs $event): void
    {
        $this->note($event->getObject(), WriteHappened::DELETED);
    }

    public function postFlush(PostFlushEventArgs $event): void
    {
        if ([] === $this->waiting) {
            return;
        }

        // Erst leeren, dann melden: scheitert ein Beobachter, sollen
        // dieselben Vorgaenge nicht beim naechsten Speichern noch einmal
        // kommen.
        $writes = $this->waiting;
        $this->waiting = [];

        foreach ($this->observers as $observer) {
            $observer->saw($writes);
        }
    }

    private function note(object $entity, string $action): void
    {
        $this->waiting[] = new WriteHappened($entity, $action, $this->clock->now());
    }
}
