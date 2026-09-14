<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Auth\Application;

use App\Module\Auth\Application\Rbac\EffectivePermissions;
use App\Module\Auth\Application\Rbac\LastUserManager;
use App\Module\Auth\Domain\Rbac\AuthPermissions;
use App\Module\Auth\Domain\Rbac\GrantedPermissions;
use App\Module\Auth\Domain\Rbac\Role;
use App\Module\Auth\Domain\User;
use App\Tests\Module\Auth\Fixture\Accounts;
use App\Tests\Module\Auth\Fixture\InMemoryPermissionAssignments;
use App\Tests\Module\Auth\Fixture\InMemoryUserRepository;
use PHPUnit\Framework\TestCase;

/**
 * Die Regel, die verhindert, dass sich eine Installation selbst aussperrt.
 *
 * Frueher hiess sie „letzter Administrator". Seit Rechte weiterreichbar sind,
 * geht es um das letzte Konto mit `users.edit` — egal, woher es das hat.
 * Bei Selbst-Hosting gibt es keinen Support, der es hinterher richtet.
 */
final class LastUserManagerTest extends TestCase
{
    private InMemoryPermissionAssignments $assignments;

    protected function setUp(): void
    {
        $this->assignments = new InMemoryPermissionAssignments();
    }

    public function testProtectsTheOnlyAdministrator(): void
    {
        $admin = Accounts::administrator('admin@example.org');
        $rule = $this->ruleFor($admin, Accounts::member('mieter@example.org'));

        self::assertTrue($rule->isTheOnlyOne($admin));
    }

    public function testLetsGoOnceThereIsASecond(): void
    {
        $first = Accounts::administrator('erste@example.org');
        $second = Accounts::administrator('zweite@example.org');
        $rule = $this->ruleFor($first, $second);

        self::assertFalse($rule->isTheOnlyOne($first));
        self::assertFalse($rule->isTheOnlyOne($second));
    }

    /**
     * Ein deaktiviertes Konto zaehlt nicht mit: es kann sich nicht anmelden
     * und damit auch niemanden befreien.
     */
    public function testADeactivatedAccountDoesNotCount(): void
    {
        $active = Accounts::administrator('aktiv@example.org');
        $sleeping = Accounts::administrator('ruht@example.org');
        $sleeping->deactivate();

        $rule = $this->ruleFor($active, $sleeping);

        self::assertTrue($rule->isTheOnlyOne($active), 'Der einzige, der noch herein kann');
        self::assertFalse($rule->isTheOnlyOne($sleeping), 'Ein abgeschaltetes Konto schützt nichts');
    }

    public function testAnOrdinaryAccountIsNotProtected(): void
    {
        $member = Accounts::member('mieter@example.org');

        self::assertFalse($this->ruleFor($member)->isTheOnlyOne($member));
    }

    /**
     * Der Kern der neuen Fassung: wer die Verwaltung ueber eine Rolle
     * bekommen hat, zaehlt genauso — und schuetzt damit auch sich selbst.
     */
    public function testAnAccountThatManagesUsersThroughARoleCounts(): void
    {
        $management = Accounts::role('Verwaltung');
        $this->assignments->setForRole($management->id(), GrantedPermissions::of([AuthPermissions::USERS_EDIT]));

        $manager = Accounts::member('verwaltung@example.org', $management);
        $admin = Accounts::administrator('admin@example.org');
        $rule = $this->ruleFor($manager, $admin);

        self::assertFalse($rule->isTheOnlyOne($admin), 'Es gibt jemanden zweiten, der verwalten kann');
        self::assertFalse($rule->isTheOnlyOne($manager));
    }

    /** Auch ein Zusatzrecht am Konto zaehlt — es ist derselbe Schluessel. */
    public function testADirectGrantCountsTheSame(): void
    {
        $manager = Accounts::member('extra@example.org');
        $this->assignments->setForUser($manager->id(), GrantedPermissions::of([AuthPermissions::USERS_EDIT]));

        $admin = Accounts::administrator('admin@example.org');

        self::assertFalse($this->ruleFor($manager, $admin)->isTheOnlyOne($admin));
    }

    /** Die Systemrolle hat alles, auch ohne eine einzige Zeile in der Matrix. */
    public function testTheSystemRoleNeedsNoAssignment(): void
    {
        $admin = Accounts::member('admin@example.org', Role::administrator());

        self::assertTrue($this->ruleFor($admin)->isTheOnlyOne($admin));
    }

    private function ruleFor(User ...$users): LastUserManager
    {
        $effective = new EffectivePermissions($this->assignments, Accounts::catalogue());

        return new LastUserManager((new InMemoryUserRepository(...$users))->knowing($effective));
    }
}
