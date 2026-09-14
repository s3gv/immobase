<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Tests\Shared\Fixture\RecordingHandler;
use App\Tests\Shared\Fixture\SomethingHappened;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class DomainEventBusTest extends KernelTestCase
{
    public function testEventWithoutHandlerIsNotAnError(): void
    {
        self::bootKernel();

        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        // Ein Event ohne Empfänger darf nicht werfen. Bewiesen wird das über
        // den zurückgegebenen Umschlag statt über assertTrue(true) — der wäre
        // keine Aussage, sondern nur eine Beruhigung.
        $envelope = $bus->dispatch(new SomethingHappened('unit-42'));
        $message = $envelope->getMessage();

        self::assertInstanceOf(SomethingHappened::class, $message);
        self::assertSame('unit-42', $message->unitId);
    }

    public function testEventReachesItsHandler(): void
    {
        self::bootKernel();

        $handler = self::getContainer()->get(RecordingHandler::class);
        self::assertInstanceOf(RecordingHandler::class, $handler);

        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        $bus->dispatch(new SomethingHappened('unit-42'));

        self::assertSame(['unit-42'], $handler->seen);
    }
}
