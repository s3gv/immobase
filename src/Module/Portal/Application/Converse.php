<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\Application;

use App\Module\Auth\Contract\AuthenticatedUser;
use App\Module\Portal\Domain\Attachment;
use App\Module\Portal\Domain\Enquiry;
use App\Module\Portal\Domain\EnquiryRepository;
use App\Module\Portal\Domain\FileVault;
use App\Module\Portal\Domain\FileVaultIsNotReady;
use App\Module\Portal\Domain\Message;
use App\Module\Portal\Domain\TooManyAttachments;
use App\Shared\Identity\Uuid;
use DateTimeImmutable;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Ein Gespraech fuehren: fragen, antworten, Dateien anhaengen.
 *
 * Beide Richtungen an einer Stelle, weil es dieselbe Handlung ist — der
 * Unterschied steht an der Nachricht, nicht im Ablauf. Auch die Frist gilt in
 * beide Richtungen: eine Datei der Verwaltung verschwindet genauso wie eine
 * des Mieters.
 */
final readonly class Converse
{
    public function __construct(
        private EnquiryRepository $enquiries,
        private FileVault $vault,
        private PortalSettings $settings,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Eine neue Anfrage aus dem Portal.
     *
     * @param list<UploadedFile> $files
     *
     * @throws TooManyAttachments
     * @throws FileVaultIsNotReady
     */
    public function ask(string $partyId, string $who, string $subject, string $body, array $files = []): Enquiry
    {
        $now = $this->clock->now();
        $enquiry = new Enquiry($this->enquiries->nextNumber(), $partyId, $subject, $now);
        $this->attach(new Message($enquiry, null, $who, $body, $now), $files, $now);
        $this->enquiries->save($enquiry);

        return $enquiry;
    }

    /**
     * Eine Antwort aus dem Portal — dieselbe Anfrage, ein neuer Beitrag.
     *
     * @param list<UploadedFile> $files
     *
     * @throws TooManyAttachments
     * @throws FileVaultIsNotReady
     */
    public function replyFromThePortal(Enquiry $enquiry, string $who, string $body, array $files = []): void
    {
        $now = $this->clock->now();
        $this->attach(new Message($enquiry, null, $who, $body, $now), $files, $now);
        $this->enquiries->save($enquiry);
    }

    /**
     * Eine Antwort der Verwaltung.
     *
     * **Wer antwortet, wird dabei zugewiesen**, wenn es noch niemand ist —
     * sonst stuende am Monatsende die Haelfte ohne Bearbeiter da, obwohl jede
     * bearbeitet wurde.
     *
     * Und hier entsteht der Termin fuer die Benachrichtigung. Er wird nicht
     * verschoben, wenn schon einer ansteht: bei fortlaufendem Schreiben kaeme
     * sonst nie eine Mail.
     *
     * @param list<UploadedFile> $files
     *
     * @throws TooManyAttachments
     * @throws FileVaultIsNotReady
     */
    public function answer(Enquiry $enquiry, AuthenticatedUser $staff, string $body, array $files = []): void
    {
        $now = $this->clock->now();
        $this->attach(new Message($enquiry, $staff->id, self::labelOf($staff), $body, $now), $files, $now);

        $enquiry->assignTo($enquiry->assigneeUserId() ?? $staff->id);
        $enquiry->notifyAt($now->add($this->settings->notifyAfter()));

        $this->enquiries->save($enquiry);
    }

    /**
     * „Katrin Schneider · Buchhaltung · katrin@…" — eingefroren.
     *
     * Damit der Empfaenger weiss, mit wem er spricht. Die Berufsbezeichnung
     * faellt weg, wo keine hinterlegt ist: eine erfundene waere schlimmer als
     * keine.
     */
    private static function labelOf(AuthenticatedUser $staff): string
    {
        $parts = array_filter(
            [$staff->displayName, $staff->jobTitle, $staff->email],
            static fn (string $part): bool => '' !== $part,
        );

        return implode(' · ', $parts);
    }

    /**
     * @param list<UploadedFile> $files
     *
     * @throws TooManyAttachments
     * @throws FileVaultIsNotReady
     */
    private function attach(Message $message, array $files, DateTimeImmutable $now): void
    {
        $kept = array_values(array_filter($files, static fn (UploadedFile $file): bool => $file->isValid()));

        if (\count($kept) > Attachment::MAX_PER_MESSAGE) {
            throw TooManyAttachments::tooMany();
        }

        $deleteAfter = $now->add($this->settings->retention());

        foreach ($kept as $file) {
            $this->sealInto($message, $file, $now, $deleteAfter);
        }
    }

    /**
     * Eine Datei versiegeln und an die Nachricht haengen.
     *
     * **Die Kennung entsteht hier und nicht in der Entity.** Sie geht als
     * zusaetzliche Daten in die Verschluesselung ein, muss also feststehen,
     * bevor versiegelt wird — und die Zeile entsteht erst mit dem
     * Geheimtext.
     *
     * Sie kommt aus {@see Uuid} und nicht aus blossen Hexziffern: PostgreSQL
     * gibt eine UUID-Spalte mit Bindestrichen zurueck. Eine Kennung ohne sie
     * waere beim naechsten Laden eine andere Zeichenkette — und damit andere
     * zusaetzliche Daten, mit denen sich der Anhang nicht mehr oeffnen
     * liesse.
     *
     * @throws TooManyAttachments
     * @throws FileVaultIsNotReady
     */
    private function sealInto(
        Message $message,
        UploadedFile $file,
        DateTimeImmutable $now,
        DateTimeImmutable $deleteAfter,
    ): void {
        if ($file->getSize() > Attachment::MAX_BYTES) {
            throw TooManyAttachments::tooLarge();
        }

        $id = Uuid::v4();

        new Attachment(
            $message,
            $id,
            $file->getClientOriginalName(),
            $file->getClientMimeType(),
            (int) $file->getSize(),
            $this->vault->seal((string) file_get_contents($file->getPathname()), $id),
            $now,
            $deleteAfter,
        );
    }
}
