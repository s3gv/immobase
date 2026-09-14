<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\UserInterface\Console;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Ein Passwort, ohne dass es in der Prozessliste steht.
 *
 * Am Terminal verdeckt erfragt, sonst die erste Zeile der Standardeingabe —
 * so reicht install.sh es herein. Als Argument stuende es fuer jeden im
 * Container in `/proc`, solange der Befehl laeuft.
 */
final class PasswordFromInput
{
    public static function read(InputInterface $input, SymfonyStyle $io): string
    {
        if ($input->isInteractive()) {
            $question = new Question('Password');
            $question->setHidden(true);
            $answer = $io->askQuestion($question);

            return \is_string($answer) ? $answer : '';
        }

        $stream = $input instanceof StreamableInputInterface ? $input->getStream() : null;
        $line = fgets($stream ?? \STDIN);

        return false === $line ? '' : rtrim($line, "\r\n");
    }
}
