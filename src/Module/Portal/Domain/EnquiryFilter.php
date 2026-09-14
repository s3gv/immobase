<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\Domain;

use App\Shared\Text\Trimmed;

/**
 * Wonach die Liste der Anfragen eingeschraenkt wird.
 *
 * Zustand, Bearbeiter, Gelesen — und eine Suche ueber den Betreff. Nach dem
 * **Inhalt** der Nachrichten wird ausdruecklich nicht gesucht: ein Gespraech
 * ist Post an einen Menschen, und eine Volltextsuche darueber ist etwas
 * anderes als eine Liste von Vorgaengen.
 */
final readonly class EnquiryFilter
{
    /** Ein eigener Wert neben den Zustaenden: ungelesen ist keiner von ihnen. */
    public const string UNREAD = 'unread';

    private function __construct(
        public ?EnquiryState $state,
        public ?string $assigneeUserId,
        public bool $unreadOnly,
        public ?string $search,
    ) {
    }

    public static function none(): self
    {
        return new self(null, null, false, null);
    }

    public static function of(?string $state, ?string $assigneeUserId, ?string $search): self
    {
        return new self(
            EnquiryState::tryFrom($state ?? ''),
            Trimmed::orNull($assigneeUserId ?? ''),
            self::UNREAD === $state,
            Trimmed::orNull($search ?? ''),
        );
    }

    public function isEmpty(): bool
    {
        return null === $this->state && null === $this->assigneeUserId && !$this->unreadOnly && null === $this->search;
    }

    /**
     * Die Werte des Zustandsfilters — die Zustaende und „ungelesen".
     *
     * @return list<string>
     */
    public static function choices(): array
    {
        return [...array_map(static fn (EnquiryState $s): string => $s->value, EnquiryState::cases()), self::UNREAD];
    }
}
