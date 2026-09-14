<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\UserInterface\Console;

use App\Module\Plugin\Application\DeliverEvents;
use App\Module\Plugin\Application\SweepDeliveries;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Traegt faellige Webhooks aus und raeumt aufgegebene weg.
 *
 * Laeuft in derselben Schleife wie die Aufraeumer des Portals und des
 * Protokolls — kein neues Stueck Technik, kein zweiter Behaelter.
 *
 * **Laeuft dieser Behaelter nicht, ist nichts kaputt.** Die Zustellungen
 * warten in der Ablage; sie gehen hinaus, sobald er wieder laeuft.
 */
#[AsCommand(
    name: 'immobase:plugin:deliver',
    description: 'Stellt fällige Webhooks an Plugins zu.',
)]
final class DeliverEventsCommand extends Command
{
    public function __construct(
        private readonly DeliverEvents $deliver,
        private readonly SweepDeliveries $sweep,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $delivered = ($this->deliver)();
        $gone = ($this->sweep)();

        (new SymfonyStyle($input, $output))->success(
            \sprintf('%d Zustellungen angekommen, %d aufgegebene weggeräumt.', $delivered, $gone),
        );

        return Command::SUCCESS;
    }
}
