<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Settings\Infrastructure;

use App\Module\Settings\Domain\Logo;
use App\Module\Settings\Domain\LogoRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use LogicException;

/**
 * Die eine Zeile mit dem Logo.
 *
 * Hier steht die Registry und nicht der Entity-Manager, weil hier als
 * einziger Stelle nach einem gescheiterten `flush()` weitergearbeitet wird:
 * Doctrine schliesst den Manager dabei, und ein geschlossener Manager kann
 * nichts mehr. Der zweite Anlauf braucht einen frischen.
 */
final readonly class DoctrineLogoRepository implements LogoRepository
{
    public function __construct(private ManagerRegistry $managers)
    {
    }

    /** Kein „das juengste": es gibt genau einen Schluessel. */
    public function current(): ?Logo
    {
        $logo = $this->manager()->getRepository(Logo::class)->find(Logo::ONLY);

        return $logo instanceof Logo ? $logo : null;
    }

    /**
     * Speichern — und ein gleichzeitiger erster Upload ersetzt statt zu scheitern.
     *
     * Zwei Formulare im selben Augenblick sehen beide „noch keines" und legen
     * beide an. Der Primaerschluessel laesst nur eines durch; der andere
     * findet die Zeile jetzt vor und schreibt seinen Inhalt hinein. Wer ein
     * Logo hochlaedt, will es dort sehen — eine Absage waere hier nur die
     * Aufforderung, dasselbe noch einmal zu tun.
     */
    public function save(Logo $logo): void
    {
        try {
            $this->write($logo);
        } catch (UniqueConstraintViolationException) {
            $this->managers->resetManager();

            $existing = $this->current()
                ?? throw new LogicException('Der Schlüssel war vergeben, die Zeile ist keine.');

            // Die Bytes sind schon geprueft — sie kommen aus einem Logo.
            $existing->replaceWith($logo->bytes(), $logo->updatedAt());

            $this->write($existing);
        }
    }

    public function remove(Logo $logo): void
    {
        $manager = $this->manager();
        $manager->remove($logo);
        $manager->flush();
    }

    private function write(Logo $logo): void
    {
        $manager = $this->manager();
        $manager->persist($logo);
        $manager->flush();
    }

    private function manager(): ObjectManager
    {
        return $this->managers->getManagerForClass(Logo::class)
            ?? throw new LogicException('Für das Logo ist kein Entity-Manager zuständig.');
    }
}
