<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Der verschluesselte Inhalt einer Datei — Geheimtext und Nonce.
 *
 * **In der Datenbank und nicht auf einer Platte.** Der Grund ist das
 * Loeschen: liegen die Bytes im Dateisystem, gibt es drei Wege, auf denen
 * doch etwas stehen bleibt — der Aufraeumer laeuft nicht, ein Absturz
 * zwischen Zeile und `unlink`, ein abgebrochener Upload. Liegen sie in der
 * Zeile, ist Loeschen dasselbe wie Vergessen: es gibt nichts, was liegen
 * bleiben koennte. Kein Pfad, keine Rechte, kein Path-Traversal, keine
 * Waisen.
 *
 * **Verschluesselt**, weil ein Datenbankabzug sonst die Belege im Klartext
 * enthielte. Base64 allein waere keine Antwort gewesen — es ist eine
 * Umschrift, kein Schutz, und kostet ein Drittel mehr Platz.
 *
 * Dass hier trotzdem Base64 steht, ist nur die Transportform: was kodiert
 * wird, ist bereits Geheimtext. Eine Binaerspalte gaebe beim Lesen einen
 * Datenstrom zurueck, und Datenstroeme in einer Entity sind eine Quelle von
 * Fehlern, die niemand erwartet.
 */
#[ORM\Embeddable]
final readonly class SealedFile
{
    #[ORM\Column(type: Types::TEXT)]
    private string $nonce;

    #[ORM\Column(type: Types::TEXT)]
    private string $cipher;

    private function __construct(string $nonce, string $cipher)
    {
        $this->nonce = $nonce;
        $this->cipher = $cipher;
    }

    public static function of(string $nonce, string $cipher): self
    {
        return new self(base64_encode($nonce), base64_encode($cipher));
    }

    public function nonce(): string
    {
        return (string) base64_decode($this->nonce, true);
    }

    public function cipher(): string
    {
        return (string) base64_decode($this->cipher, true);
    }
}
