<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Command;

use Claudriel\Command\MigratePersonTiersCommand;
use Claudriel\Entity\Person;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

final class MigratePersonTiersCommandTest extends TestCase
{
    public function test_updates_person_tiers(): void
    {
        $person1 = new Person(['email' => 'jane@example.com', 'name' => 'Jane', 'tier' => 'contact']);
        $person2 = new Person(['email' => 'noreply@github.com', 'name' => 'GitHub', 'tier' => 'contact']);

        $repo = $this->createMock(EntityRepositoryInterface::class);
        $repo->method('findBy')->willReturn([$person1, $person2]);
        $repo->expects($this->once())->method('save')->with($person2);

        $command = new MigratePersonTiersCommand($repo);
        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Updated 1 person(s)', $tester->getDisplay());
    }

    public function test_no_updates_when_tiers_correct(): void
    {
        $repo = $this->createMock(EntityRepositoryInterface::class);
        $repo->method('findBy')->willReturn([]);
        $repo->expects($this->never())->method('save');

        $command = new MigratePersonTiersCommand($repo);
        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertStringContainsString('Updated 0 person(s)', $tester->getDisplay());
    }
}
