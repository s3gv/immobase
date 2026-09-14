<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\Application;

use App\Module\Settings\Contract\ApplicationSettings;
use DateInterval;

/**
 * Die beiden Stellschrauben des Portals — mit ihren Grenzen.
 *
 * Die Grenzen stehen am Vertrag der Einstellungen und nicht hier: das
 * Formular braucht sie ebenso wie diese Stelle, und zwei Listen von Grenzen
 * liefen auseinander. Gelesen wird trotzdem begrenzt — ein Wert, der auf
 * einem anderen Weg hereinkam, soll nicht gelten, nur weil er in der Zeile
 * steht. Eine Aufbewahrung von dreihundert Tagen waere ein Archiv, und ein
 * Archiv will gepflegt werden.
 */
final readonly class PortalSettings
{
    public function __construct(private ApplicationSettings $settings)
    {
    }

    public function retentionDays(): int
    {
        return self::within(
            $this->settings->int(
                ApplicationSettings::PORTAL_FILE_RETENTION_DAYS,
                ApplicationSettings::PORTAL_FILE_RETENTION_DEFAULT,
            ),
            ApplicationSettings::PORTAL_FILE_RETENTION_MIN,
            ApplicationSettings::PORTAL_FILE_RETENTION_MAX,
        );
    }

    public function retention(): DateInterval
    {
        return new DateInterval('P'.$this->retentionDays().'D');
    }

    public function notifyAfterMinutes(): int
    {
        return self::within(
            $this->settings->int(
                ApplicationSettings::PORTAL_NOTIFY_AFTER_MINUTES,
                ApplicationSettings::PORTAL_NOTIFY_AFTER_DEFAULT,
            ),
            ApplicationSettings::PORTAL_NOTIFY_AFTER_MIN,
            ApplicationSettings::PORTAL_NOTIFY_AFTER_MAX,
        );
    }

    public function notifyAfter(): DateInterval
    {
        return new DateInterval('PT'.$this->notifyAfterMinutes().'M');
    }

    private static function within(int $value, int $least, int $most): int
    {
        return max($least, min($most, $value));
    }
}
