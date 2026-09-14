<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use DomainException;

/**
 * Diese Bewegung ist schon storniert.
 *
 * Ein zweites Storno waere keine Korrektur mehr, sondern eine Aenderung an
 * der Geschichte.
 */
final class AlreadyReversed extends DomainException
{
}
