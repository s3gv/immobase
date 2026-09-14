<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

foreach (glob(dirname(__DIR__).'/var/cache/prod/*.preload.php') ?: [] as $file) {
    require $file;
}
