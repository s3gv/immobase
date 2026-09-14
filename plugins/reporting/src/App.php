<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace Reporting;

use DateTimeImmutable;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

/**
 * Das Plugin als Ganzes: vier Seiten und ein Webhook.
 *
 * **Ausgeliefert werden Fragmente.** Der Core holt sie serverseitig und setzt
 * sie in seine eigene Shell — deshalb steht hier kein `<html>` und kein
 * `<head>`. Wer sein Plugin ausserhalb des Netzes betreibt und ganze Seiten
 * liefert, bindet stattdessen das Stylesheet des Cores unter
 * `/plugin-api/v1/theme.css` ein.
 *
 * **Kein Dauerlauf.** Gespiegelt wird, wenn ein Webhook kommt oder wenn eine
 * Seite aufgerufen wird und der Spiegel alt ist. Ein zweiter Prozess neben
 * dem Webserver waere fuer vier Auswertungen zu viel Apparat.
 */
final readonly class App
{
    public function __construct(
        private Mirror $mirror,
        private Reports $reports,
        private Tiles $tiles,
        private Core $core,
        private Store $store,
        private Environment $twig,
    ) {
    }

    public static function build(): self
    {
        $core = Core::fromEnvironment();
        $store = new Store($core);
        $reports = new Reports($store);

        $twig = new Environment(new FilesystemLoader(\dirname(__DIR__).'/templates'), ['strict_variables' => true]);

        // Betraege ohne Gleitkomma: Twigs number_format wandelt in float um.
        $twig->addFilter(new TwigFilter('betrag', static fn (mixed $value): string => Decimal::format(\is_scalar($value) ? (string) $value : '0', 2)));

        return new self(
            new Mirror($core, $store, static fn (): DateTimeImmutable => new DateTimeImmutable()),
            $reports,
            new Tiles($store, $reports),
            $core,
            $store,
            $twig,
        );
    }

    /**
     * @param array<string, string> $headers Kopfzeilen, Namen klein geschrieben
     */
    public function handle(string $method, string $path, string $body = '', array $headers = []): void
    {
        if ('/webhook' === $path && 'POST' === $method) {
            $this->onChange($body, $headers);

            return;
        }

        $page = self::PAGES[$path] ?? null;

        if (null === $page || 'GET' !== $method) {
            http_response_code(404);

            return;
        }

        $this->render($page);
    }

    /** Pfad zu Vorlage — derselbe Pfad steht im Manifest. */
    private const array PAGES = ['/berichte' => 'berichte'];

    private function render(string $page): void
    {
        $this->refreshUnlessCovered((new DateTimeImmutable())->modify(Mirror::FRESH));

        header('Content-Type: text/html; charset=utf-8');

        echo $this->twig->render($page.'.html.twig', [
            ...$this->dataFor($page),
            'stand' => $this->store->lastSync('full')?->format('d.m.Y, H:i'),
        ]);
    }

    /**
     * Alle vier Auswertungen auf einmal.
     *
     * Sie stehen auf einer Seite, also werden sie zusammen geholt. Vier
     * Abfragen auf einem Spiegel, der ohnehin im Arbeitsspeicher der
     * Datenbank liegt — das ist billiger als vier Seitenaufrufe.
     *
     * @return array<string, mixed>
     */
    private function dataFor(string $page): array
    {
        return [
            'costs' => $this->reports->costTrend(),
            'claims' => $this->reports->claims(),
            'occupancy' => $this->reports->occupancy(),
            ...$this->reports->reservesAndLoans(),
        ];
    }

    /**
     * Der Core meldet eine Aenderung.
     *
     * **Zuerst die Unterschrift.** Ohne sie wird nichts gespiegelt und nichts
     * geschoben — sonst koennte jeder Prozess im Container teure Spiegelungen
     * ausloesen.
     *
     * Die Nutzlast traegt nur den Hinweis; was es war, holt dieses Plugin
     * ueber die Schnittstelle. Gespiegelt wird nur, wenn der Spiegel **vor**
     * der Aenderung geholt wurde — bei einer Massenaenderung kommen hundert
     * Meldungen, und nach der ersten Spiegelung sind die uebrigen schon
     * enthalten.
     *
     * **Die Kennung der Zustellung wird verlangt, aber nicht gespeichert.**
     * Sie ist dafuer da, dieselbe Meldung nicht zweimal zu verarbeiten. Hier
     * loest jede Meldung dieselbe vollstaendige Spiegelung aus; eine doppelt
     * zugestellte ist nach der ersten Spiegelung schon enthalten und kostet
     * nichts. Ein Plugin, das pro
     * Meldung etwas zaehlt oder anlegt, muss sich die Kennungen merken.
     *
     * @param array<string, string> $headers
     */
    private function onChange(string $body, array $headers): void
    {
        $signature = $headers['x-immobase-signature'] ?? '';
        $delivery = $headers['x-immobase-delivery'] ?? '';

        if ('' === $delivery || !$this->core->signed($body, $signature)) {
            http_response_code(401);

            return;
        }

        $this->refreshUnlessCovered(self::changedAt($body));

        http_response_code(204);
    }

    /** Nach einer Spiegelung gehen die Kacheln an den Core — ohne eine nicht. */
    private function refreshUnlessCovered(DateTimeImmutable $since): void
    {
        if ($this->mirror->refreshUnlessCovered($since)) {
            $this->core->showTiles($this->tiles->all());
        }
    }

    /**
     * Wann sich etwas geaendert hat — laut Meldung.
     *
     * Fehlt der Zeitpunkt oder ist er unlesbar, gilt „jetzt": lieber einmal zu
     * oft spiegeln als eine Aenderung verpassen.
     */
    private static function changedAt(string $body): DateTimeImmutable
    {
        $payload = json_decode($body, true);
        $at = \is_array($payload) ? $payload['at'] ?? null : null;
        $parsed = \is_string($at) ? DateTimeImmutable::createFromFormat(DateTimeImmutable::ATOM, $at) : false;

        return false === $parsed ? new DateTimeImmutable() : $parsed;
    }
}
