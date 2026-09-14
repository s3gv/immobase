<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\UserInterface\Console;

use App\Module\Portal\Application\SweepThePortal;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Raeumt abgelaufene Anhaenge weg und verschickt faellige Benachrichtigungen.
 *
 * Gedacht fuer einen zweiten Container aus demselben Image, der ihn in
 * Schleife ruft — kein neues Stueck Technik, keine zweite Sprache, dieselbe
 * Datenbankverbindung. Neustartverhalten und Protokoll trennt Docker ohnehin.
 */
#[AsCommand(
    name: 'immobase:portal:sweep',
    description: 'Löscht abgelaufene Anhänge und verschickt fällige Benachrichtigungen.',
)]
final class SweepPortalCommand extends Command
{
    public function __construct(private readonly SweepThePortal $sweep)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $done = ($this->sweep)();
        $style = new SymfonyStyle($input, $output);

        $style->success(\sprintf(
            '%d Anhänge gelöscht, %d Benachrichtigungen verschickt.',
            $done['forgotten'],
            $done['notified'],
        ));

        return Command::SUCCESS;
    }
}
