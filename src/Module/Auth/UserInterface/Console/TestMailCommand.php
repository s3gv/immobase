<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\UserInterface\Console;

use App\Shared\Mail\OutgoingMail;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Schickt eine Probe-E-Mail.
 *
 * install.sh ruft ihn auf, nachdem ein Mailserver eingetragen wurde. Ein
 * Mailserver, der erst bei der ersten echten Einladung auffaellt, kostet mehr
 * Zeit als die Probe.
 *
 * Bewusst am Sammler vorbei direkt ueber den Mailer: hier soll ein Fehler
 * sichtbar werden und nicht im Log landen.
 */
#[AsCommand(
    name: 'immobase:mail:test',
    description: 'Sends a test email to check the mail server settings.',
)]
final class TestMailCommand extends Command
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly OutgoingMail $mail,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('to', InputArgument::REQUIRED, 'Empfängeradresse');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $to = $input->getArgument('to');

        if (!\is_string($to)) {
            $io->error('The recipient address must be a string.');

            return Command::INVALID;
        }

        if (!$this->mail->isConfigured()) {
            $io->warning('Es ist kein Mailserver eingerichtet (MAILER_DSN). Es wurde nichts verschickt.');

            return Command::FAILURE;
        }

        try {
            $this->mailer->send($this->probe($to));
        } catch (TransportExceptionInterface $failure) {
            $io->error('Sending failed: '.$failure->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('Test email sent to %s.', $to));

        return Command::SUCCESS;
    }

    private function probe(string $to): Email
    {
        return (new Email())
            ->from($this->mail->sender())
            ->to($to)
            ->subject('ImmoBase: Testnachricht')
            ->text(
                "Diese Nachricht bestätigt, dass ImmoBase E-Mails verschicken kann.\n\n"
                ."Kommt sie an, funktionieren Einladungen und das Zurücksetzen von Passwörtern.\n",
            );
    }
}
