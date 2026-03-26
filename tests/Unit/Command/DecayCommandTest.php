<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Command;

use Claudriel\Command\DecayCommand;
use Claudriel\Entity\Account;
use Claudriel\Entity\Commitment;
use Claudriel\Entity\McEvent;
use Claudriel\Entity\Person;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

final class DecayCommandTest extends TestCase
{
    public function test_decay_updates_entities_using_tenant_settings(): void
    {
        $person = new Person(['uuid' => 'p-1', 'tenant_id' => 'tenant-1', 'importance_score' => 1.0, 'updated_at' => '2026-01-01T00:00:00+00:00']);
        $commitment = new Commitment(['uuid' => 'c-1', 'tenant_id' => 'tenant-1', 'importance_score' => 1.0, 'updated_at' => '2026-01-01T00:00:00+00:00']);
        $event = new McEvent(['uuid' => 'e-1', 'tenant_id' => 'tenant-1', 'importance_score' => 1.0, 'updated_at' => '2026-01-01T00:00:00+00:00']);
        $account = new Account(['tenant_id' => 'tenant-1', 'settings' => ['decay_rate_daily' => 0.9, 'min_importance_threshold' => 0.1]]);

        $personRepo = $this->createMock(EntityRepositoryInterface::class);
        $personRepo->method('findBy')->willReturn([$person]);
        $personRepo->expects($this->once())->method('save')->with($person);

        $commitmentRepo = $this->createMock(EntityRepositoryInterface::class);
        $commitmentRepo->method('findBy')->willReturn([$commitment]);
        $commitmentRepo->expects($this->once())->method('save')->with($commitment);

        $eventRepo = $this->createMock(EntityRepositoryInterface::class);
        $eventRepo->method('findBy')->willReturn([$event]);
        $eventRepo->expects($this->once())->method('save')->with($event);

        $accountRepo = $this->createMock(EntityRepositoryInterface::class);
        $accountRepo->method('findBy')->willReturn([$account]);

        $command = new DecayCommand(
            $personRepo,
            $commitmentRepo,
            $eventRepo,
            $accountRepo,
            nowFactory: static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-03-26T10:00:00+00:00'),
        );
        $tester = new CommandTester($command);
        $tester->execute(['--tenant' => 'tenant-1']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('updated=3', $tester->getDisplay());
        self::assertSame(0.9, (float) $person->get('importance_score'));
    }

    public function test_decay_is_idempotent_within_same_day(): void
    {
        $today = '2026-03-26T08:00:00+00:00';
        $person = new Person(['uuid' => 'p-1', 'importance_score' => 0.8, 'updated_at' => $today]);

        $personRepo = $this->createMock(EntityRepositoryInterface::class);
        $personRepo->method('findBy')->willReturn([$person]);
        $personRepo->expects($this->never())->method('save');

        $commitmentRepo = $this->createMock(EntityRepositoryInterface::class);
        $commitmentRepo->method('findBy')->willReturn([]);
        $commitmentRepo->expects($this->never())->method('save');

        $eventRepo = $this->createMock(EntityRepositoryInterface::class);
        $eventRepo->method('findBy')->willReturn([]);
        $eventRepo->expects($this->never())->method('save');

        $accountRepo = $this->createMock(EntityRepositoryInterface::class);
        $accountRepo->method('findBy')->willReturn([]);

        $command = new DecayCommand(
            $personRepo,
            $commitmentRepo,
            $eventRepo,
            $accountRepo,
            nowFactory: static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-03-26T10:00:00+00:00'),
        );
        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertStringContainsString('updated=0', $tester->getDisplay());
        self::assertStringContainsString('skipped_already_decayed=1', $tester->getDisplay());
    }

    public function test_decay_respects_tenant_isolation(): void
    {
        $tenantA = new Person(['uuid' => 'p-a', 'tenant_id' => 'tenant-a', 'updated_at' => '2026-01-01T00:00:00+00:00']);
        $tenantB = new Person(['uuid' => 'p-b', 'tenant_id' => 'tenant-b', 'updated_at' => '2026-01-01T00:00:00+00:00']);

        $personRepo = $this->createMock(EntityRepositoryInterface::class);
        $personRepo->expects($this->once())
            ->method('findBy')
            ->with(['tenant_id' => 'tenant-a'])
            ->willReturn([$tenantA]);
        $personRepo->expects($this->once())->method('save')->with($tenantA);

        $commitmentRepo = $this->createMock(EntityRepositoryInterface::class);
        $commitmentRepo->expects($this->once())
            ->method('findBy')
            ->with(['tenant_id' => 'tenant-a'])
            ->willReturn([]);
        $commitmentRepo->expects($this->never())->method('save');

        $eventRepo = $this->createMock(EntityRepositoryInterface::class);
        $eventRepo->expects($this->once())
            ->method('findBy')
            ->with(['tenant_id' => 'tenant-a'])
            ->willReturn([]);
        $eventRepo->expects($this->never())->method('save');

        $accountRepo = $this->createMock(EntityRepositoryInterface::class);
        $accountRepo->expects($this->once())
            ->method('findBy')
            ->with(['tenant_id' => 'tenant-a'])
            ->willReturn([]);

        $command = new DecayCommand(
            $personRepo,
            $commitmentRepo,
            $eventRepo,
            $accountRepo,
            nowFactory: static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-03-26T10:00:00+00:00'),
        );
        $tester = new CommandTester($command);
        $tester->execute(['--tenant' => 'tenant-a']);

        self::assertNotSame($tenantA->get('updated_at'), $tenantB->get('updated_at'));
        self::assertStringContainsString('updated=1', $tester->getDisplay());
    }
}
