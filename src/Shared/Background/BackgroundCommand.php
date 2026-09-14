<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Background;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Der Hintergrundlauf — neben dem Webserver im Anwendungscontainer.
 *
 * **Kein eigener Container.** Die Anwendung besteht aus App, Datenbank und
 * (in der Entwicklung) dem Mailfaenger; ein Behaelter nur dafuer, jede
 * Minute zwei Befehle aufzurufen, waere Apparat ohne Gewinn. Die Schleife im
 * Container startet diesen Lauf neu, falls er stirbt — der Webserver merkt
 * davon nichts.
 */
#[AsCommand(
    name: 'immobase:background',
    description: 'Erledigt die Hintergrundarbeiten: aufräumen, benachrichtigen, Plugins betreiben.',
)]
final class BackgroundCommand extends Command
{
    private const int SECONDS = 5;

    public function __construct(
        private readonly BackgroundRound $round,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('once', null, InputOption::VALUE_NONE, 'Nur einen Durchgang, dann beenden.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        while (true) {
            // Ein Lauf, der tagelang lebt, saehe sonst nie, was in der
            // Oberflaeche geaendert wurde: er hielte den Stand vom Start fest.
            $this->entityManager->clear();

            foreach (($this->round)() as $failure) {
                $output->writeln('<error>Hintergrund: '.$failure.'</error>');
            }

            if (true === $input->getOption('once')) {
                return Command::SUCCESS;
            }

            sleep(self::SECONDS);
        }
    }
}
