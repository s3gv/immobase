<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\Infrastructure;

use App\Module\Portal\Domain\Attachment;
use App\Module\Portal\Domain\AttachmentRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineAttachmentRepository implements AttachmentRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function byId(string $id): ?Attachment
    {
        return $this->entityManager->getRepository(Attachment::class)->find($id);
    }

    /**
     * Eine Anweisung und keine Schleife ueber geladene Zeilen.
     *
     * Die Bytes stehen in denselben Zeilen; sie zu laden, um sie danach
     * wegzuwerfen, hiesse bei hundert abgelaufenen Anhaengen ein halbes
     * Gigabyte durch den Speicher zu schieben, damit am Ende nichts davon
     * bleibt.
     */
    public function forgetExpired(DateTimeImmutable $on): int
    {
        $gone = $this->entityManager->createQuery(
            'DELETE FROM '.Attachment::class.' a WHERE a.deleteAfter <= :now',
        )
            ->setParameter('now', $on)
            ->execute();

        // Geladene Anhaenge in dieser Anfrage wissen noch nichts davon.
        $this->entityManager->clear();

        return is_numeric($gone) ? (int) $gone : 0;
    }
}
