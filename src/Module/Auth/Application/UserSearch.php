<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Application;

use App\Module\Auth\Domain\Rbac\AuthPermissions;
use App\Module\Auth\Domain\User;
use App\Module\Auth\Domain\UserFilter;
use App\Module\Auth\Domain\UserRepository;
use App\Shared\Search\SearchesRecords;
use App\Shared\Search\SearchHit;
use App\Shared\Search\SearchTerm;
use App\Shared\Ui\Page;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Die Benutzerkonten in der zentralen Suche.
 *
 * Nur mit dem Recht, die Benutzerverwaltung zu sehen. Eine Liste von Namen
 * und Mailadressen ist eine Auskunft ueber die Belegschaft, und wer sie nicht
 * oeffnen darf, soll sie auch nicht ertippen koennen.
 *
 * Mit den deaktivierten: wer ein abgeschaltetes Konto sucht, sucht es, weil
 * er wissen will, ob es noch da ist.
 */
#[AsTaggedItem(priority: 10)]
final readonly class UserSearch implements SearchesRecords
{
    public function __construct(
        private UserRepository $users,
        private AuthorizationCheckerInterface $mayView,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function kindKey(): string
    {
        return 'search.kind.user';
    }

    public function listUrl(SearchTerm $term): string
    {
        return $this->urls->generate('app_user', ['q' => $term->raw]);
    }

    public function matching(SearchTerm $term, int $limit): array
    {
        if (!$this->mayView->isGranted(AuthPermissions::USERS_VIEW)) {
            return [];
        }

        $filter = UserFilter::of($term->text, null, withPast: true);
        $found = $this->users->matching($filter, Page::of(1, Page::PER_PAGE));

        return array_map(
            fn (User $user): SearchHit => new SearchHit(
                title: $user->displayName(),
                subtitle: $user->email()->toString(),
                reference: (string) $user->number(),
                url: $this->urls->generate('app_user_show', ['number' => $user->number()]),
            ),
            \array_slice($found, 0, $limit),
        );
    }
}
