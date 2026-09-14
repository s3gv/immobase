<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Shared\Ui;

use App\Shared\Ui\Action;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Haelt das Aktionsvokabular vollstaendig.
 *
 * Eine neue Aktion ohne Symbol oder ohne Beschriftung faellt sonst erst im
 * Browser auf — als leerer Knopf oder als roher Uebersetzungsschluessel.
 */
final class ActionTest extends TestCase
{
    #[DataProvider('actions')]
    public function testEveryActionHasASymbol(Action $action): void
    {
        self::assertFileExists(
            self::projectDir().'/assets/images/icons/'.$action->icon().'.svg',
            \sprintf('Für die Aktion "%s" fehlt das Symbol.', $action->value),
        );
    }

    #[DataProvider('actions')]
    public function testEveryActionIsNamedInEveryLanguage(Action $action): void
    {
        foreach (['de', 'en'] as $language) {
            $messages = Yaml::parseFile(self::projectDir().'/translations/messages.'.$language.'.yaml');

            self::assertIsArray($messages);
            self::assertArrayHasKey('action', $messages);
            self::assertIsArray($messages['action']);
            self::assertArrayHasKey(
                $action->value,
                $messages['action'],
                \sprintf('action.%s fehlt in %s.', $action->value, $language),
            );
        }
    }

    public function testNoTwoActionsShareASymbol(): void
    {
        $icons = array_map(static fn (Action $action): string => $action->icon(), Action::cases());

        self::assertSame(
            $icons,
            array_values(array_unique($icons)),
            'Zwei Aktionen mit demselben Symbol sind für Nutzer nicht unterscheidbar.',
        );
    }

    public function testOnlyDeletingIsMarkedDestructive(): void
    {
        $destructive = array_filter(Action::cases(), static fn (Action $a): bool => $a->isDestructive());

        self::assertSame([Action::Delete], array_values($destructive));
    }

    /**
     * @return iterable<string, array{Action}>
     */
    public static function actions(): iterable
    {
        foreach (Action::cases() as $action) {
            yield $action->value => [$action];
        }
    }

    private static function projectDir(): string
    {
        return \dirname(__DIR__, 3);
    }
}
