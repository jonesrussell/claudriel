<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Command;

use Claudriel\Command\ConsolidateCommand;
use Claudriel\Domain\Memory\DuplicateDetector;
use Claudriel\Entity\Person;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

final class ConsolidateCommandTest extends TestCase
{
    public function test_creates_merge_candidates_from_detector_results(): void
    {
        $personA = new Person(['uuid' => 'p1', 'email' => 'jane@example.com', 'name' => 'Jane']);
        $personB = new Person(['uuid' => 'p2', 'email' => 'jane@example.com', 'name' => 'J Doe']);

        $personRepo = $this->createMock(EntityRepositoryInterface::class);
        $personRepo->expects($this->once())
            ->method('findBy')
            ->with(['tenant_id' => 'tenant-1'])
            ->willReturn([$personA, $personB]);

        $mergeCandidateRepo = $this->createMock(EntityRepositoryInterface::class);
        $mergeCandidateRepo->expects($this->once())->method('save');

        $command = new ConsolidateCommand($personRepo, $mergeCandidateRepo, new DuplicateDetector);
        $tester = new CommandTester($command);
        $status = $tester->execute(['--tenant' => 'tenant-1']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('candidates=1', $tester->getDisplay());
    }

    public function test_dry_run_does_not_persist_candidates(): void
    {
        $personA = new Person(['uuid' => 'p1', 'email' => 'jane@example.com']);
        $personB = new Person(['uuid' => 'p2', 'email' => 'jane@example.com']);

        $personRepo = $this->createMock(EntityRepositoryInterface::class);
        $personRepo->method('findBy')->willReturn([$personA, $personB]);

        $mergeCandidateRepo = $this->createMock(EntityRepositoryInterface::class);
        $mergeCandidateRepo->expects($this->never())->method('save');

        $command = new ConsolidateCommand($personRepo, $mergeCandidateRepo, new DuplicateDetector);
        $tester = new CommandTester($command);
        $tester->execute(['--dry-run' => true]);

        self::assertStringContainsString('dry_run=yes', $tester->getDisplay());
    }
}
