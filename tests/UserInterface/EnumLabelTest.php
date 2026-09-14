<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\UserInterface;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionEnum;
use SplFileInfo;
use Symfony\Component\Yaml\Yaml;
use UnitEnum;

/**
 * Jede Aufzaehlung, die eine Beschriftung verspricht, muss sie auch haben.
 *
 * Der Fall, der diesen Test ausgeloest hat: die Heizungsarten bildeten
 * `property.heating.type.gas`, in den Uebersetzungen hiess der Zweig aber
 * `type_option` — weil `type` daneben schon die Feldbeschriftung war. Auf dem
 * Bildschirm stand daraufhin „property.heating.type.gas" in einer
 * Auswahlliste. Symfony faellt bei einem fehlenden Schluessel stillschweigend
 * auf den Schluessel selbst zurueck; auffallen kann das nur hier oder im
 * Browser.
 *
 * Dieselbe Idee wie beim Rechtekatalog: was der Code verspricht, prueft der
 * Testlauf gegen das, was in den Sprachdateien steht.
 */
final class EnumLabelTest extends TestCase
{
    private const array LOCALES = ['de', 'en'];

    public function testEveryLabelKeyExistsInEveryLanguage(): void
    {
        $messages = array_map(self::messagesOf(...), array_combine(self::LOCALES, self::LOCALES));
        $checked = 0;

        foreach (self::labelKeys() as $class => $keys) {
            foreach ($keys as $key) {
                foreach ($messages as $locale => $translations) {
                    self::assertKeyExists($translations, $key, $locale, $class);
                    ++$checked;
                }
            }
        }

        self::assertGreaterThan(0, $checked, 'Es wurde keine einzige Aufzählung gefunden');
    }

    /**
     * Alle labelKey()-Werte aller Aufzaehlungen unter src/.
     *
     * Aufgerufen wird ueber Reflexion und nicht ueber den Typ: welche
     * Aufzaehlung eine Beschriftung verspricht, steht in keinem Interface —
     * genau das macht diesen Test noetig.
     *
     * @return array<string, list<string>>
     */
    private static function labelKeys(): array
    {
        $found = [];

        foreach (self::enums() as $class) {
            $reflection = new ReflectionEnum($class);

            if (!$reflection->hasMethod('labelKey')
                || 0 !== $reflection->getMethod('labelKey')->getNumberOfParameters()) {
                continue;
            }

            $method = $reflection->getMethod('labelKey');
            $keys = [];

            foreach ($reflection->getCases() as $case) {
                $key = $method->invoke($case->getValue());

                if (\is_string($key)) {
                    $keys[] = $key;
                }
            }

            $found[$class] = $keys;
        }

        return $found;
    }

    /**
     * @return list<class-string<UnitEnum>>
     */
    private static function enums(): array
    {
        $classes = [];
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(\dirname(__DIR__, 2).'/src', FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }

            $class = self::classIn((string) file_get_contents($file->getPathname()));

            if (null !== $class && enum_exists($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * @return class-string|null
     */
    private static function classIn(string $source): ?string
    {
        if (1 !== preg_match('/^namespace\s+([^;]+);/m', $source, $namespace)
            || 1 !== preg_match('/^enum\s+(\w+)/m', $source, $name)) {
            return null;
        }

        /** @var class-string $class */
        $class = $namespace[1].'\\'.$name[1];

        return $class;
    }

    /**
     * Gelesen wird die Datei und nicht der Uebersetzer: der faellt bei einer
     * fehlenden englischen Zeichenkette auf die deutsche zurueck, und die
     * Luecke waere genau da nicht zu sehen, wo dieser Test hinschaut.
     *
     * @return array<mixed, mixed>
     */
    private static function messagesOf(string $locale): array
    {
        $parsed = Yaml::parseFile(\dirname(__DIR__, 2).'/translations/messages.'.$locale.'.yaml');
        self::assertIsArray($parsed);

        return $parsed;
    }

    /**
     * @param array<mixed, mixed> $messages
     */
    private static function assertKeyExists(array $messages, string $key, string $locale, string $class): void
    {
        $node = $messages;

        foreach (explode('.', $key) as $segment) {
            self::assertIsArray($node, \sprintf('%s (aus %s) fehlt in messages.%s.yaml.', $key, $class, $locale));
            self::assertArrayHasKey($segment, $node, \sprintf(
                '%s fehlt in messages.%s.yaml — %s verspricht ihn. Ohne ihn steht der Schlüssel auf dem Bildschirm.',
                $key,
                $locale,
                $class,
            ));
            $node = $node[$segment];
        }

        self::assertIsString($node, \sprintf('%s ist in messages.%s.yaml kein Text.', $key, $locale));
    }
}
