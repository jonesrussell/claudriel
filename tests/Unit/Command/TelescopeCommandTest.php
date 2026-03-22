<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Command;

use Claudriel\Command\TelescopeCommand;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Telescope\Storage\SqliteTelescopeStore;
use Waaseyaa\Telescope\TelescopeServiceProvider;

final class TelescopeCommandTest extends TestCase
{
    public function test_formats_request_entries(): void
    {
        $store = SqliteTelescopeStore::createInMemory();
        $telescope = new TelescopeServiceProvider(store: $store);

        $telescope->getRequestRecorder()->record('GET', '/brief', 200, 12.5);
        $telescope->getRequestRecorder()->record('POST', '/graphql', 200, 45.3);

        $command = new TelescopeCommand($telescope);
        $output = $command->formatEntries('request', 10);

        self::assertStringContainsString('GET', $output);
        self::assertStringContainsString('/brief', $output);
        self::assertStringContainsString('200', $output);
        self::assertStringContainsString('POST', $output);
        self::assertStringContainsString('/graphql', $output);
    }

    public function test_filters_by_type(): void
    {
        $store = SqliteTelescopeStore::createInMemory();
        $telescope = new TelescopeServiceProvider(store: $store);

        $telescope->getRequestRecorder()->record('GET', '/brief', 200, 12.5);
        $telescope->getEventRecorder()->record('EntitySaved', ['id' => 1]);

        $command = new TelescopeCommand($telescope);

        $requestOutput = $command->formatEntries('request', 10);
        self::assertStringContainsString('/brief', $requestOutput);
        self::assertStringNotContainsString('EntitySaved', $requestOutput);
    }

    public function test_shows_empty_message_when_no_entries(): void
    {
        $store = SqliteTelescopeStore::createInMemory();
        $telescope = new TelescopeServiceProvider(store: $store);

        $command = new TelescopeCommand($telescope);
        $output = $command->formatEntries('request', 10);

        self::assertStringContainsString('No entries', $output);
    }
}
