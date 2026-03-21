<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Command;

use Claudriel\Command\GitHubSyncCommand;
use Claudriel\Entity\McEvent;
use Claudriel\Entity\Person;
use Claudriel\Ingestion\EventHandler;
use Claudriel\Ingestion\GitHubNotificationNormalizer;
use Claudriel\Support\GitHubTokenManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;

final class GitHubSyncCommandTest extends TestCase
{
    public function test_command_name_is_correct(): void
    {
        $command = $this->buildCommand();
        $this->assertSame('claudriel:github:sync', $command->getName());
    }

    public function test_skips_when_no_integration(): void
    {
        $tokenManager = $this->createMock(GitHubTokenManagerInterface::class);
        $tokenManager->method('getValidAccessToken')
            ->willThrowException(new \RuntimeException('No active GitHub integration found for this account. Connect GitHub at /github/connect'));

        $command = $this->buildCommand($tokenManager);
        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Skipping GitHub sync', $tester->getDisplay());
    }

    public function test_skips_when_integration_revoked(): void
    {
        $tokenManager = $this->createMock(GitHubTokenManagerInterface::class);
        $tokenManager->method('getValidAccessToken')
            ->willThrowException(new \RuntimeException('GitHub integration has been revoked'));

        $command = $this->buildCommand($tokenManager);
        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Skipping GitHub sync', $tester->getDisplay());
        self::assertStringContainsString('revoked', $tester->getDisplay());
    }

    private function buildCommand(?GitHubTokenManagerInterface $tokenManager = null): GitHubSyncCommand
    {
        $tokenManager ??= $this->createMock(GitHubTokenManagerInterface::class);
        $dispatcher = new EventDispatcher;

        $eventRepo = new EntityRepository(
            new EntityType(id: 'mc_event', label: 'Event', class: McEvent::class, keys: ['id' => 'eid', 'uuid' => 'uuid']),
            new InMemoryStorageDriver,
            $dispatcher,
        );
        $personRepo = new EntityRepository(
            new EntityType(id: 'person', label: 'Person', class: Person::class, keys: ['id' => 'pid', 'uuid' => 'uuid', 'label' => 'name']),
            new InMemoryStorageDriver,
            $dispatcher,
        );

        $eventHandler = new EventHandler($eventRepo, $personRepo);
        $normalizer = new GitHubNotificationNormalizer;

        return new GitHubSyncCommand($tokenManager, $eventHandler, $normalizer, 'test-tenant');
    }
}
