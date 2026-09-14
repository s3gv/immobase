<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Infrastructure;

use App\Module\Auth\Application\Notifier;
use App\Module\Auth\Domain\User;
use App\Shared\Mail\OutgoingMail;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;

/**
 * Verschickt die Nachrichten des Auth-Moduls per E-Mail.
 *
 * Jede Nachricht geht als Text *und* HTML hinaus. Der Textteil ist kein
 * Zugestaendnis an alte Programme: er ist der Teil, den ein Filter lesen
 * kann, ohne ihn als Werbung einzustufen, und der auch dann noch stimmt, wenn
 * jemand Bilder und Stile abschaltet.
 */
final readonly class MailNotifier implements Notifier
{
    public function __construct(private OutgoingMail $mail)
    {
    }

    public function invite(User $user, string $link): void
    {
        $this->send($user, 'invite', ['link' => $link]);
    }

    public function resetPassword(User $user, string $link): void
    {
        $this->send($user, 'reset', ['link' => $link]);
    }

    public function secondFactorCode(User $user, string $code): void
    {
        $this->send($user, 'second_factor', ['code' => $code]);
    }

    public function confirmEmailChange(User $user, string $newAddress, string $link): void
    {
        $this->mail->queue(
            $this->message($user, 'email_change', ['link' => $link, 'newAddress' => $newAddress])
                ->to($newAddress),
        );
    }

    public function passwordChanged(User $user): void
    {
        $this->send($user, 'password_changed', []);
    }

    public function emailChanged(User $user, string $previousAddress, string $newAddress): void
    {
        $this->mail->queue(
            $this->message($user, 'email_changed', ['newAddress' => $newAddress])
                ->to($previousAddress),
        );
    }

    public function isConfigured(): bool
    {
        return $this->mail->isConfigured();
    }

    /**
     * @param array<string, string> $context
     */
    private function send(User $user, string $template, array $context): void
    {
        $this->mail->queue($this->message($user, $template, $context)->to($user->email()->toString()));
    }

    /**
     * @param array<string, string> $context
     */
    private function message(User $user, string $template, array $context): TemplatedEmail
    {
        return (new TemplatedEmail())
            ->subject($this->mail->subject('email.'.$template.'.subject'))
            ->textTemplate('email/'.$template.'.txt.twig')
            ->htmlTemplate('email/'.$template.'.html.twig')
            ->context([...$context, 'name' => $user->displayName()]);
    }
}
