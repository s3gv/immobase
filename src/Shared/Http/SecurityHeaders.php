<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Die Schutz-Kopfzeilen jeder Antwort.
 *
 * **Content-Security-Policy:** Skripte nur von hier, das eine eingebettete
 * (Theme vor dem ersten Bild, Importmap) nur mit der Nonce dieser Anfrage.
 * Inline-Handler wie `onclick=` sind damit verboten — eine eingeschleuste
 * Zeile HTML fuehrt kein Skript aus, auch wenn sie irgendwo durchrutscht.
 * Stil-Attribute bleiben erlaubt: Diagramme und die Zeitleiste setzen Breiten
 * und Positionen so, und CSS allein fuehrt nichts aus. Nachladen kann es auch
 * nichts von woanders — Bilder und Schriften nur von hier, sonst liesse sich
 * ueber Attribut-Selektoren ein Formularwert nach aussen tragen.
 *
 * **Keine Einbettung** in fremde Seiten (Clickjacking), kein Raten des
 * Dateityps, keine Adresse in fremde Referer, kein Zugriff auf Kamera und Co.
 *
 * **HSTS** nur ueber https: ueber http bewirkt es nichts, und wer ImmoBase
 * hinter einem Proxy ohne TLS betreibt, soll sich damit nicht aussperren.
 *
 * Eine Antwort, die ihre eigene Policy mitbringt, behaelt sie.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -512)]
final readonly class SecurityHeaders
{
    public function __construct(private CspNonce $nonce)
    {
    }

    public function __invoke(ResponseEvent $event): void
    {
        $headers = $event->getResponse()->headers;

        $defaults = [
            'Content-Security-Policy' => $this->policy(),
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'same-origin',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()',
        ];

        foreach ($defaults as $name => $value) {
            if (!$headers->has($name)) {
                $headers->set($name, $value);
            }
        }

        if ($event->getRequest()->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000');
        }
    }

    private function policy(): string
    {
        return implode('; ', [
            "default-src 'self'",
            \sprintf("script-src 'self' 'nonce-%s'", $this->nonce->value()),
            \sprintf("style-src 'self' 'nonce-%s'", $this->nonce->value()),
            "style-src-attr 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self'",
            "connect-src 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "base-uri 'none'",
            "object-src 'none'",
        ]);
    }
}
