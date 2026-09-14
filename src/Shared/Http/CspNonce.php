<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Http;

use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Die Nonce der laufenden Anfrage fuer eingebettete Skripte.
 *
 * Einmal je Anfrage erzeugt und nach ihr verworfen — unter FrankenPHP laufen
 * viele Anfragen durch denselben Prozess, und eine wiederverwendete Nonce
 * schuetzte nichts. In Vorlagen: `<script nonce="{{ csp_nonce() }}">`.
 *
 * Der Filter `with_style_nonce` gibt den `<style>`-Bloecken eines fremden
 * Fragments die Nonce — ein Plugin bringt seine Stile eingebettet mit, weil
 * der Core ein Fragment holt und kein Dokument. Skripte bekommen sie nicht:
 * was ein Plugin an `<script>` liefert, fuehrt der Browser nicht aus.
 */
final class CspNonce extends AbstractExtension implements ResetInterface
{
    private ?string $value = null;

    public function getFunctions(): array
    {
        return [new TwigFunction('csp_nonce', $this->value(...))];
    }

    public function getFilters(): array
    {
        return [new TwigFilter('with_style_nonce', $this->styles(...))];
    }

    public function styles(string $html): string
    {
        return (string) preg_replace('/<style(?=[\s>])/i', \sprintf('<style nonce="%s"', $this->value()), $html);
    }

    public function value(): string
    {
        return $this->value ??= base64_encode(random_bytes(18));
    }

    public function reset(): void
    {
        $this->value = null;
    }
}
