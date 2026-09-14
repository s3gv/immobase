<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

/**
 * Der Eingang des Auswertungs-Plugins.
 *
 * Ein eigener Prozess mit eigenem Autoloader. **Nichts aus dem ImmoBase-Core
 * wird hier geladen** — das ist der Grund, warum dieses Plugin ein
 * eigenstaendiges Werk bleibt und beliebig lizenziert werden darf.
 */

require \dirname(__DIR__).'/vendor/autoload.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', \PHP_URL_PATH);

// Die Kopfzeilen, die dieses Plugin braucht — in kleinen Buchstaben, so wie
// HTTP sie ohnehin meint.
$headers = [];

foreach (['HTTP_X_IMMOBASE_SIGNATURE' => 'x-immobase-signature', 'HTTP_X_IMMOBASE_DELIVERY' => 'x-immobase-delivery'] as $server => $name) {
    if (\is_string($_SERVER[$server] ?? null)) {
        $headers[$name] = $_SERVER[$server];
    }
}

try {
    Reporting\App::build()->handle(
        \is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET',
        \is_string($path) ? $path : '/',
        (string) file_get_contents('php://input'),
        $headers,
    );
} catch (Throwable $failed) {
    // Der Core zeigt bei einer Absage eine ruhige Seite. Was hier schiefging,
    // gehoert ins Protokoll dieses Containers und nicht in fremde Augen.
    error_log('reporting: '.$failed->getMessage());
    http_response_code(500);
}
