// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

/**
 * Holt den offenen Plugin-Menüpunkt in den sichtbaren Teil seiner Liste.
 *
 * Die Liste rollt, wenn viele Plugins installiert sind. Ohne diese Zeilen
 * stünde man auf einer Seite, deren Menüpunkt gerade weggerollt ist — und
 * sähe nicht, wo man ist.
 *
 * `block: 'nearest'` rollt nur, wenn es nötig ist, und bewegt dabei auch
 * nicht die Seite: alles andere wäre ein Sprung bei jedem Aufruf.
 */

document
    .querySelector('.ib-nav-plugins .ib-nav__link.is-current')
    ?.scrollIntoView({ block: 'nearest' });
