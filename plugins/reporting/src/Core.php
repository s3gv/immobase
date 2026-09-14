<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace Reporting;

use RuntimeException;

/**
 * Der ImmoBase-Core, von aussen gesehen.
 *
 * Ein HTTP-Client und sonst nichts. **Keine Klasse des Cores wird hier
 * geladen** — dieses Plugin kennt ihn nur als Adresse, ein Token und eine
 * Handvoll JSON-Formen. Genau deshalb bleibt es ein eigenstaendiges Werk und
 * darf beliebig lizenziert sein.
 *
 * Absichtlich ohne HTTP-Bibliothek: curl genuegt, und wer diese Datei liest,
 * soll sehen koennen, was tatsaechlich ueber die Leitung geht.
 */
final readonly class Core implements Source
{
    public function __construct(
        private string $url,
        private string $token,
    ) {
    }

    public static function fromEnvironment(): self
    {
        $url = getenv('IMMOBASE_URL');
        $token = getenv('IMMOBASE_TOKEN');

        if (!\is_string($url) || '' === $url || !\is_string($token) || '' === $token) {
            throw new RuntimeException('IMMOBASE_URL und IMMOBASE_TOKEN müssen gesetzt sein.');
        }

        return new self(rtrim($url, '/'), $token);
    }

    /**
     * Eine ganze Ressource, Seite fuer Seite.
     *
     * Der Core blättert in Portionen; wer den Bestand spiegeln will, geht sie
     * durch. Die Zahl der Seiten steht in jeder Antwort.
     *
     * @return iterable<array<string, mixed>>
     */
    public function everything(string $resource): iterable
    {
        $page = 1;

        do {
            $answer = $this->get('/api/v1/'.$resource.'?page='.$page);
            $data = $answer['data'] ?? [];
            $pages = $answer['pages'] ?? 1;

            foreach (\is_array($data) ? $data : [] as $record) {
                if (\is_array($record)) {
                    yield $record;
                }
            }

            ++$page;
        } while (\is_int($pages) && $page <= $pages);
    }

    /**
     * Kacheln auf die Uebersicht des Cores stellen.
     *
     * Geschoben und nicht abgeholt: der Core soll beim Seitenaufbau niemanden
     * fragen muessen. Alle auf einmal — was nicht mitkommt, verschwindet.
     *
     * @param list<array<string, mixed>> $tiles
     */
    public function showTiles(array $tiles): void
    {
        $this->send('PUT', '/api/v1/plugins/self/tiles', json_encode($tiles, \JSON_THROW_ON_ERROR));
    }

    /** Ist diese Zustellung vom Core unterschrieben? */
    public function signed(string $body, string $signature): bool
    {
        return Signature::isValid($body, $signature, $this->token);
    }

    /** Die Zugangsdaten zum eigenen Schema — der Core haendigt sie gegen das Token aus. */
    public function storageDsn(): string
    {
        $answer = $this->get('/api/v1/plugins/self/storage');
        $data = $answer['data'] ?? [];
        $dsn = \is_array($data) ? $data['dsn'] ?? null : null;

        if (!\is_string($dsn)) {
            throw new RuntimeException('Der Core hat keine Verbindungsangabe geliefert.');
        }

        return $dsn;
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $path): array
    {
        return $this->send('GET', $path, null);
    }

    /**
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, ?string $body): array
    {
        $handle = curl_init($this->url.$path);

        if (false === $handle) {
            throw new RuntimeException('curl ließ sich nicht öffnen.');
        }

        // Einzeln gesetzt und nicht ueber einen Spread zusammengebaut: die
        // CURLOPT_-Konstanten sind Ganzzahlen, und `...` nummeriert
        // Ganzzahlschluessel neu — aus CURLOPT_POSTFIELDS wuerde Option 0,
        // und der Rumpf ginge stillschweigend nicht mit.
        $options = [
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_CUSTOMREQUEST => $method,
            \CURLOPT_TIMEOUT => 30,
            \CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$this->token, 'Content-Type: application/json'],
        ];

        if (null !== $body) {
            $options[\CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($handle, $options);

        $answer = curl_exec($handle);
        $status = curl_getinfo($handle, \CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if (!\is_string($answer) || $status < 200 || $status >= 300) {
            throw new RuntimeException(\sprintf('%s %s beantwortet mit %d.', $method, $path, $status));
        }

        $decoded = json_decode($answer, true);

        return \is_array($decoded) ? $decoded : [];
    }
}
