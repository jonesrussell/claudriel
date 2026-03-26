<?php

declare(strict_types=1);

namespace Claudriel\Command;

use Claudriel\Domain\Memory\DuplicateDetector;
use Claudriel\Entity\MergeCandidate;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Waaseyaa\Entity\ContentEntityInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

#[AsCommand(name: 'claudriel:consolidate', description: 'Detect duplicate entities and create merge candidates')]
final class ConsolidateCommand extends Command
{
    public function __construct(
        private readonly EntityRepositoryInterface $personRepo,
        private readonly EntityRepositoryInterface $mergeCandidateRepo,
        private readonly DuplicateDetector $detector = new DuplicateDetector,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Tenant UUID to process')
            ->addOption('entity-type', null, InputOption::VALUE_REQUIRED, 'Entity type to process', 'person')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show candidates without persisting')
            ->addOption('threshold', null, InputOption::VALUE_REQUIRED, 'Similarity threshold', '0.8');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $entityType = (string) ($input->getOption('entity-type') ?? 'person');
        if ($entityType !== 'person') {
            $output->writeln('Only entity-type=person is supported in this phase.');

            return Command::INVALID;
        }

        $tenantId = $input->getOption('tenant');
        $tenantId = is_string($tenantId) && $tenantId !== '' ? $tenantId : null;
        $dryRun = (bool) $input->getOption('dry-run');
        $threshold = (float) ($input->getOption('threshold') ?? 0.8);

        $criteria = $tenantId !== null ? ['tenant_id' => $tenantId] : [];
        /** @var ContentEntityInterface[] $persons */
        $persons = $this->personRepo->findBy($criteria);
        $candidates = $this->detector->detectPersonDuplicates($persons, $threshold);

        $created = 0;
        foreach ($candidates as $candidate) {
            if (! $dryRun) {
                $entity = new MergeCandidate([
                    'uuid' => $this->generateUuid(),
                    'source_entity_type' => 'person',
                    'source_entity_uuid' => $candidate['source_uuid'],
                    'target_entity_type' => 'person',
                    'target_entity_uuid' => $candidate['target_uuid'],
                    'similarity_score' => $candidate['similarity_score'],
                    'match_reasons' => json_encode($candidate['match_reasons'], JSON_THROW_ON_ERROR),
                    'status' => 'pending',
                    'tenant_id' => $tenantId,
                ]);
                $this->mergeCandidateRepo->save($entity);
            }
            $created++;
        }

        $output->writeln(sprintf(
            'Consolidation scan complete. candidates=%d threshold=%.2f dry_run=%s',
            $created,
            $threshold,
            $dryRun ? 'yes' : 'no',
        ));

        return Command::SUCCESS;
    }

    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xFFFF), random_int(0, 0xFFFF),
            random_int(0, 0xFFFF),
            random_int(0, 0x0FFF) | 0x4000,
            random_int(0, 0x3FFF) | 0x8000,
            random_int(0, 0xFFFF), random_int(0, 0xFFFF), random_int(0, 0xFFFF),
        );
    }
}
