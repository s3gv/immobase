<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Infrastructure;

use App\Module\Auth\Domain\Rbac\GrantedPermissions;
use App\Module\Auth\Domain\Rbac\PermissionAssignments;
use App\Shared\Audit\AuditAction;
use App\Shared\Audit\RecordsActions;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Die beiden Zuordnungstabellen, direkt ueber DBAL.
 *
 * Sie halten Zeichenketten und keine Objekte: als Doctrine-Entities waeren es
 * zwei Klassen mit zusammengesetztem Schluessel, deren ganzer Inhalt ein
 * String ist. Der Umweg ueber die ORM brauchte hier mehr Code, als er
 * abnimmt.
 *
 * **Dafuer meldet dieser Weg sich selbst ans Protokoll.** Es haengt an
 * Doctrines Lebenszyklus, und ein `DELETE` ueber DBAL loest keines aus —
 * eine Rechtevergabe ginge sonst als einziger Vorgang der Anwendung
 * spurlos durch, ausgerechnet der, bei dem am ehesten jemand wissen will,
 * wer ihn ausgeloest hat.
 */
final readonly class DoctrinePermissionAssignments implements PermissionAssignments
{
    private const string ROLE_TABLE = 'auth_role_permission';
    private const string USER_TABLE = 'auth_user_permission';

    public function __construct(
        private Connection $connection,
        private RecordsActions $protocol,
    ) {
    }

    public function ofRole(string $roleId): GrantedPermissions
    {
        return $this->ofRoles([$roleId])[$roleId] ?? GrantedPermissions::none();
    }

    public function ofRoles(array $roleIds): array
    {
        if ([] === $roleIds) {
            return [];
        }

        /** @var list<array{role_id: string, permission_key: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT role_id, permission_key FROM '.self::ROLE_TABLE.' WHERE role_id IN (:ids)',
            ['ids' => $roleIds],
            ['ids' => ArrayParameterType::STRING],
        );

        $keys = [];

        foreach ($rows as $row) {
            $keys[$row['role_id']][] = $row['permission_key'];
        }

        return array_map(static fn (array $list): GrantedPermissions => GrantedPermissions::of($list), $keys);
    }

    public function ofUser(string $userId): GrantedPermissions
    {
        /** @var list<string> $keys */
        $keys = $this->connection->fetchFirstColumn(
            'SELECT permission_key FROM '.self::USER_TABLE.' WHERE user_id = ?',
            [$userId],
        );

        return GrantedPermissions::of($keys);
    }

    public function setForRole(string $roleId, GrantedPermissions $permissions): void
    {
        $this->replace(self::ROLE_TABLE, 'role_id', $roleId, 'Role', $permissions);
    }

    public function setForUser(string $userId, GrantedPermissions $permissions): void
    {
        $this->replace(self::USER_TABLE, 'user_id', $userId, 'User', $permissions);
    }

    /**
     * Schluessel wegraeumen, die es im Code nicht mehr gibt.
     *
     * **Nicht protokolliert**, anders als jede Vergabe: hier verliert niemand
     * ein Recht, das er hatte — der Schluessel selbst existiert nicht mehr,
     * und was ihn trug, war seit dem Wegfall wirkungslos. Eine Zeile „Rechte
     * entzogen" waere hier eine falsche Auskunft.
     */
    public function forgetUnknown(array $known): int
    {
        $removed = 0;

        foreach ([self::ROLE_TABLE, self::USER_TABLE] as $table) {
            $removed += [] === $known
                ? (int) $this->connection->executeStatement('DELETE FROM '.$table)
                : (int) $this->connection->executeStatement(
                    'DELETE FROM '.$table.' WHERE permission_key NOT IN (:known)',
                    ['known' => $known],
                    ['known' => ArrayParameterType::STRING],
                );
        }

        return $removed;
    }

    public function countsPerRole(): array
    {
        /** @var list<array{role_id: string, granted: int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT role_id, COUNT(*) AS granted FROM '.self::ROLE_TABLE.' GROUP BY role_id',
        );

        $counts = [];

        foreach ($rows as $row) {
            $counts[$row['role_id']] = (int) $row['granted'];
        }

        return $counts;
    }

    /**
     * Was nach der Vergabe dasteht — und nicht, was sich geaendert hat.
     *
     * Der Unterschied waere die genauere Auskunft und kostete eine zweite
     * Abfrage vor jeder Vergabe. Der Stand danach genuegt: zwei Zeilen
     * nebeneinander zeigen, was dazukam und was fortfiel, und das ist genau
     * die Frage, mit der jemand ins Protokoll sieht.
     *
     * Eine leere Vergabe ist ein Entzug und wird auch so genannt — „keine
     * Rechte" laese sich sonst als „nichts passiert".
     */
    private function noteChange(string $record, string $id, GrantedPermissions $permissions): void
    {
        $keys = $permissions->toList();

        $this->protocol->note(
            AuditAction::PermissionsChanged,
            $record,
            $id,
            [] === $keys ? 'Rechte entzogen' : 'Rechte: '.implode(', ', $keys),
        );
    }

    /**
     * Alles weg, alles neu — und die Protokollzeile dazu, in einer Transaktion.
     *
     * Ein Abgleich Zeile fuer Zeile waere sparsamer und muesste dafuer
     * wissen, was schon dasteht. Bei einer Handvoll Schluesseln je Rolle ist
     * das der teurere Weg.
     *
     * **Die Meldung steht mit darin.** Danach gemeldet, waere sie die Zeile,
     * die fehlt, wenn ihr Schreiben scheitert — und zurueck bliebe eine
     * wirksame Rechtevergabe ohne Spur. Bei diesem Vorgang ist das der eine
     * Ausgang, den es nicht geben darf: entweder beides oder keines.
     *
     * Dass die Zeile in derselben Transaktion landet, haengt daran, dass das
     * Protokoll auf derselben Verbindung schreibt.
     */
    private function replace(
        string $table,
        string $owner,
        string $id,
        string $record,
        GrantedPermissions $permissions,
    ): void {
        $this->connection->transactional(function (Connection $connection) use ($table, $owner, $id, $record, $permissions): void {
            $connection->executeStatement('DELETE FROM '.$table.' WHERE '.$owner.' = ?', [$id]);

            foreach ($permissions->toList() as $key) {
                $connection->insert($table, [$owner => $id, 'permission_key' => $key]);
            }

            $this->noteChange($record, $id, $permissions);
        });
    }
}
