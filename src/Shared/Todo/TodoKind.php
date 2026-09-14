<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Todo;

/**
 * Woher ein Todo kommt — und damit, was es von einem Menschen verlangt.
 *
 * Die vier unterscheiden sich nicht in der Dringlichkeit, sondern in der Art
 * der Arbeit. Eine Meldung entsteht von selbst und will eine Entscheidung;
 * ein Entwurf ist angefangene Arbeit und will fortgesetzt werden; eine Frist
 * laeuft und will vorher etwas; eine fehlende Angabe blockiert und will
 * nachgetragen werden. Wer morgens hereinkommt, arbeitet sie in dieser
 * Reihenfolge ab.
 */
enum TodoKind: string
{
    case Notice = 'notice';
    case Draft = 'draft';
    case Deadline = 'deadline';
    case Missing = 'missing';

    public function labelKey(): string
    {
        return 'todo.group.'.$this->value;
    }

    /**
     * In dieser Reihenfolge stehen die Gruppen untereinander.
     *
     * @return list<self>
     */
    public static function inOrder(): array
    {
        return [self::Notice, self::Deadline, self::Draft, self::Missing];
    }
}
