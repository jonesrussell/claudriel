<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Domain\Chat;

use Claudriel\Domain\Chat\ChatSystemPromptBuilder;
use Claudriel\Domain\DayBrief\Assembler\DayBriefAssembler;
use Claudriel\Entity\JudgmentRule;
use Claudriel\Support\DriftDetector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;

final class ChatSystemPromptBuilderRulesTest extends TestCase
{
    public function test_rules_injected_into_prompt(): void
    {
        $ruleRepo = $this->createRuleRepo();
        $ruleRepo->save(new JudgmentRule([
            'jrid' => 1,
            'rule_text' => 'Always CC assistant on client emails',
            'context' => 'When sending emails to clients',
            'tenant_id' => 't1',
            'status' => 'active',
            'application_count' => 5,
        ]));

        $builder = new ChatSystemPromptBuilder(
            $this->createAssembler(),
            sys_get_temp_dir(),
            ruleRepo: $ruleRepo,
            ruleTenantId: 't1',
        );

        $prompt = $builder->build('t1');

        self::assertStringContainsString('Always CC assistant on client emails', $prompt);
        self::assertStringContainsString('judgment_rules', strtolower($prompt));
    }

    public function test_no_rules_section_when_empty(): void
    {
        $ruleRepo = $this->createRuleRepo();

        $builder = new ChatSystemPromptBuilder(
            $this->createAssembler(),
            sys_get_temp_dir(),
            ruleRepo: $ruleRepo,
            ruleTenantId: 't1',
        );

        $prompt = $builder->build('t1');

        self::assertStringNotContainsString('judgment_rules', strtolower($prompt));
    }

    private function createRuleRepo(): EntityRepository
    {
        return new EntityRepository(
            new EntityType(
                id: 'judgment_rule',
                label: 'Judgment Rule',
                class: JudgmentRule::class,
                keys: ['id' => 'jrid', 'uuid' => 'uuid', 'label' => 'rule_text'],
            ),
            new InMemoryStorageDriver,
            new EventDispatcher,
        );
    }

    private function createAssembler(): DayBriefAssembler
    {
        $emptyRepo = $this->createMock(EntityRepositoryInterface::class);
        $emptyRepo->method('findBy')->willReturn([]);

        $driftDetector = new DriftDetector($emptyRepo);

        return new DayBriefAssembler($emptyRepo, $emptyRepo, $driftDetector, $emptyRepo);
    }
}
