<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Audit\UserInterface\Console;

use App\Module\Audit\Application\SweepTheTrail;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Raeumt Protokollzeilen weg, die aelter als achtundvierzig Stunden sind.
 *
 * Laeuft in derselben Schleife wie der Aufraeumer des Portals — kein neues
 * Stueck Technik, kein zweiter Behaelter.
 */
#[AsCommand(
    name: 'immobase:audit:sweep',
    description: 'Löscht Protokollzeilen, die älter als 48 Stunden sind.',
)]
final class SweepTrailCommand extends Command
{
    public function __construct(private readonly SweepTheTrail $sweep)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $gone = ($this->sweep)();

        (new SymfonyStyle($input, $output))->success(\sprintf('%d Protokollzeilen gelöscht.', $gone));

        return Command::SUCCESS;
    }
}
