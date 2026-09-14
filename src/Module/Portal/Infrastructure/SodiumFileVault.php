<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\Infrastructure;

use App\Module\Portal\Domain\FileVault;
use App\Module\Portal\Domain\FileVaultIsNotReady;
use App\Module\Portal\Domain\SealedFile;
use SensitiveParameter;
use SodiumException;

/**
 * XChaCha20-Poly1305 mit einem Schluessel aus der Umgebung.
 *
 * **Ein eigener Schluessel und nicht `APP_SECRET`.** Das wird auch fuer
 * Formular-Token benutzt, und wer es wechselt — was man tun soll, wenn es
 * abhandengekommen ist — soll nicht nebenbei alle Anhaenge unlesbar machen.
 *
 * **Die Dateikennung geht als zusaetzliche Daten ein.** Damit laesst sich ein
 * Geheimtext nicht in eine andere Zeile umhaengen: wer die Datenbank
 * bearbeiten kann, koennte sonst den Anhang einer fremden Anfrage in seine
 * eigene kopieren und ihn dort herunterladen.
 *
 * Dass ein verlorener Schluessel die Anhaenge kostet, ist hier **kein**
 * grosses Risiko: sie leben ohnehin nur Tage.
 */
final readonly class SodiumFileVault implements FileVault
{
    private const int KEY_BYTES = \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES;

    private string $key;

    public function __construct(#[SensitiveParameter] string $key)
    {
        $raw = '' === $key ? false : base64_decode($key, true);

        $this->key = false !== $raw && self::KEY_BYTES === \strlen($raw) ? $raw : '';
    }

    public function isReady(): bool
    {
        return '' !== $this->key;
    }

    public function seal(#[SensitiveParameter] string $plain, string $id): SealedFile
    {
        if (!$this->isReady()) {
            throw FileVaultIsNotReady::withoutAKey();
        }

        $nonce = random_bytes(\SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        return SealedFile::of(
            $nonce,
            sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plain, $id, $nonce, $this->key),
        );
    }

    public function open(SealedFile $sealed, string $id): ?string
    {
        if (!$this->isReady()) {
            return null;
        }

        try {
            $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                $sealed->cipher(),
                $id,
                $sealed->nonce(),
                $this->key,
            );
        } catch (SodiumException) {
            // Ein unlesbarer Anhang ist eine Meldung auf dem Blatt und kein
            // Absturz der Seite.
            return null;
        }

        return false === $plain ? null : $plain;
    }
}
