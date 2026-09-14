<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Infrastructure;

use App\Module\Auth\Domain\UserStatus;
use Doctrine\DBAL\Connection;

/**
 * Wer kann Benutzer verwalten — und wie haelt man das waehrend einer
 * Aenderung fest?
 *
 * Eine eigene Klasse, weil es eine andere Frage ist als „lade mir dieses
 * Konto": hier geht es um eine Menge, um ihre Zaehlung und um die Sperre, die
 * beides zusammenhaelt. Im Benutzerbestand stuende das als Fremdkoerper
 * zwischen den Abfragen.
 */
final readonly class ManagerGuard
{
    /**
     * Drei Wege zu demselben Recht: die Systemrolle, eine Rolle mit diesem
     * Schluessel, die direkte Zuweisung.
     *
     * Einmal geschrieben, weil Zaehlen und Sperren dieselbe Menge meinen
     * muessen. Zwei Fassungen desselben Praedikats driften auseinander, und
     * die Sperre hielte dann etwas anderes fest, als die Zaehlung liest.
     */
    private const string MANAGES = <<<'SQL'
        u.status = :status AND (
            EXISTS (
                SELECT 1 FROM auth_user_role ur
                JOIN auth_role r ON r.id = ur.role_id
                WHERE ur.user_id = u.id AND (
                    r.is_system = true
                    OR EXISTS (
                        SELECT 1 FROM auth_role_permission rp
                        WHERE rp.role_id = r.id AND rp.permission_key = :permission
                    )
                )
            )
            OR EXISTS (
                SELECT 1 FROM auth_user_permission up
                WHERE up.user_id = u.id AND up.permission_key = :permission
            )
        )
        SQL;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * Als SQL und nicht als DQL: die beiden Schluesseltabellen sind bewusst
     * keine Entities, also gibt es dafuer auch keinen DQL-Pfad.
     */
    public function count(string $permissionKey, ?string $exceptUserId = null): int
    {
        $sql = 'SELECT COUNT(*) FROM auth_user u WHERE '.self::MANAGES;
        $parameters = ['status' => UserStatus::Active->value, 'permission' => $permissionKey];

        if (null !== $exceptUserId) {
            $sql .= ' AND u.id <> :except';
            $parameters['except'] = $exceptUserId;
        }

        $count = $this->connection->fetchOne($sql, $parameters);

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * @template T
     *
     * @param callable(): T $change
     *
     * @return T
     */
    public function guarding(string $permissionKey, callable $change): mixed
    {
        return $this->connection->transactional(
            function (Connection $connection) use ($permissionKey, $change): mixed {
                $this->lock($connection, $permissionKey);

                return $change();
            },
        );
    }

    /**
     * Sperrt die Zeilen der Konten, die heute Benutzer verwalten koennen.
     *
     * SELECT … FOR UPDATE und kein COUNT: gesperrt werden Zeilen, und eine
     * Zaehlung hat keine. Ein zweiter Aufruf wartet damit hier, statt
     * gleichzeitig dieselbe Zahl zu sehen. Wenn er drankommt, liest er den
     * Stand nach der ersten Aenderung — und genau darum geht es.
     *
     * Dass die Sperre mehr Konten trifft als das eine, um das es gerade geht,
     * ist Absicht: die Frage lautet nicht „ist dieses Konto frei", sondern
     * „bleibt danach noch jemand".
     */
    private function lock(Connection $connection, string $permissionKey): void
    {
        $connection->executeQuery(
            'SELECT u.id FROM auth_user u WHERE '.self::MANAGES.' FOR UPDATE',
            ['status' => UserStatus::Active->value, 'permission' => $permissionKey],
        );
    }
}
