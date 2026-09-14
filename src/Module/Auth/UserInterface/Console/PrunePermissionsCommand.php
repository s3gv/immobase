<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\UserInterface\Console;

use App\Module\Auth\Application\Rbac\PermissionCatalogue;
use App\Module\Auth\Domain\Rbac\PermissionAssignments;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Raeumt Zuordnungen zu Rechten weg, die es nicht mehr gibt.
 *
 * Der einzige Rest, den die Katalogtests nicht sehen: sie lesen den Code, und
 * diese Zeilen stehen in der Datenbank. Faellt ein Bereich weg, bleiben seine
 * Zuordnungen stehen — gewaehren nichts, weil beim Berechnen der Rechte
 * unbekannte Schluessel uebergangen werden, aber sie stehen da.
 *
 * Kein Anwendungsfall in der Oberflaeche: es ist Datenpflege und keine
 * Entscheidung, die jemand treffen muesste.
 */
#[AsCommand(
    name: 'immobase:permission:prune',
    description: 'Entfernt Rechtezuordnungen, deren Schlüssel es nicht mehr gibt.',
)]
final class PrunePermissionsCommand extends Command
{
    public function __construct(
        private readonly PermissionAssignments $assignments,
        private readonly PermissionCatalogue $catalogue,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $removed = $this->assignments->forgetUnknown($this->catalogue->keys());

        $io = new SymfonyStyle($input, $output);

        if (0 === $removed) {
            $io->success('Es gab nichts aufzuräumen.');

            return Command::SUCCESS;
        }

        $io->success(\sprintf('%d verwaiste Zuordnung(en) entfernt.', $removed));

        return Command::SUCCESS;
    }
}
