<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\UserInterface\Console;

use App\Module\Auth\Domain\TokenRepository;
use DateInterval;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Raeumt abgelaufene Schluessel weg.
 *
 * Ausdruecklich ein Befehl und kein Aufraeumen nebenbei beim Zugriff: was
 * still im Hintergrund verschwindet, fehlt genau dann, wenn jemand
 * nachvollziehen will, warum ein Link nicht funktioniert hat.
 */
#[AsCommand(
    name: 'immobase:token:prune',
    description: 'Entfernt abgelaufene Einladungs- und Anmeldeschlüssel.',
)]
final class PruneTokensCommand extends Command
{
    private const int DEFAULT_DAYS = 30;

    public function __construct(
        private readonly TokenRepository $tokens,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'days',
            null,
            InputOption::VALUE_REQUIRED,
            'Wie lange abgelaufene Schlüssel stehen bleiben',
            (string) self::DEFAULT_DAYS,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = $input->getOption('days');
        $keep = is_numeric($days) ? max(0, (int) $days) : self::DEFAULT_DAYS;

        $removed = $this->tokens->prune($this->clock->now()->sub(new DateInterval('P'.$keep.'D')));

        (new SymfonyStyle($input, $output))->success(
            \sprintf('%d abgelaufene Schlüssel entfernt (älter als %d Tage).', $removed, $keep),
        );

        return Command::SUCCESS;
    }
}
