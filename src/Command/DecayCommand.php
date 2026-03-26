<?php

declare(strict_types=1);

namespace Claudriel\Command;

use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Waaseyaa\Entity\ContentEntityInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

#[AsCommand(name: 'claudriel:decay', description: 'Apply adaptive memory decay across entities')]
final class DecayCommand extends Command
{
    public function __construct(
        private readonly EntityRepositoryInterface $personRepo,
        private readonly EntityRepositoryInterface $commitmentRepo,
        private readonly EntityRepositoryInterface $eventRepo,
        private readonly EntityRepositoryInterface $accountRepo,
        private readonly ?\Closure $nowFactory = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Tenant UUID to process')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show results without persisting changes')
            ->addOption('verbose', null, InputOption::VALUE_NONE, 'Print per-entity updates');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tenantId = $input->getOption('tenant');
        $tenantId = is_string($tenantId) && $tenantId !== '' ? $tenantId : null;
        $dryRun = (bool) $input->getOption('dry-run');
        $verbose = (bool) $input->getOption('verbose');

        $settings = $this->resolveTenantDecaySettings($tenantId);
        $rate = $this->resolveDecayRate($settings);
        $minThreshold = $this->resolveMinThreshold($settings);
        $now = $this->now();

        $criteria = $tenantId !== null ? ['tenant_id' => $tenantId] : [];
        $updated = 0;
        $alreadyDecayed = 0;

        foreach ([['person', $this->personRepo], ['commitment', $this->commitmentRepo], ['mc_event', $this->eventRepo]] as [$entityType, $repo]) {
            /** @var ContentEntityInterface[] $entities */
            $entities = $repo->findBy($criteria);
            foreach ($entities as $entity) {
                if ($this->alreadyDecayedToday($entity, $now)) {
                    $alreadyDecayed++;

                    continue;
                }

                $oldScore = $this->normalizeScore($entity->get('importance_score'));
                $newScore = max($minThreshold, min(1.0, $oldScore * $rate));

                $entity->set('importance_score', $newScore);
                if ($entity->get('access_count') === null) {
                    $entity->set('access_count', 0);
                }
                if ($entity->get('last_accessed_at') === null) {
                    $entity->set('last_accessed_at', null);
                }
                $entity->set('updated_at', $now->format('c'));

                if (! $dryRun) {
                    $repo->save($entity);
                }
                $updated++;

                if ($verbose) {
                    $output->writeln(sprintf(
                        '%s:%s %.4f -> %.4f',
                        $entityType,
                        (string) ($entity->get('uuid') ?? 'unknown'),
                        $oldScore,
                        $newScore,
                    ));
                }
            }
        }

        $output->writeln(sprintf(
            'Decay complete. updated=%d skipped_already_decayed=%d rate=%.3f min_threshold=%.3f dry_run=%s',
            $updated,
            $alreadyDecayed,
            $rate,
            $minThreshold,
            $dryRun ? 'yes' : 'no',
        ));

        return Command::SUCCESS;
    }

    private function resolveDecayRate(array $settings): float
    {
        $rate = $settings['decay_rate_daily'] ?? 0.995;

        return is_numeric($rate) ? max(0.0, min(1.0, (float) $rate)) : 0.995;
    }

    private function resolveMinThreshold(array $settings): float
    {
        $threshold = $settings['min_importance_threshold'] ?? 0.1;

        return is_numeric($threshold) ? max(0.0, min(1.0, (float) $threshold)) : 0.1;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveTenantDecaySettings(?string $tenantId): array
    {
        if ($tenantId === null) {
            return [];
        }

        /** @var ContentEntityInterface[] $accounts */
        $accounts = $this->accountRepo->findBy(['tenant_id' => $tenantId]);
        foreach ($accounts as $account) {
            $settings = $account->get('settings');
            if (is_array($settings)) {
                return $settings;
            }
            if (is_string($settings) && $settings !== '') {
                try {
                    $decoded = json_decode($settings, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($decoded)) {
                        return $decoded;
                    }
                } catch (\Throwable) {
                    return [];
                }
            }
        }

        return [];
    }

    private function normalizeScore(mixed $value): float
    {
        if (is_numeric($value)) {
            return max(0.0, min(1.0, (float) $value));
        }

        return 1.0;
    }

    private function alreadyDecayedToday(ContentEntityInterface $entity, DateTimeImmutable $now): bool
    {
        $updatedAt = $entity->get('updated_at');
        if (! is_string($updatedAt) || $updatedAt === '') {
            return false;
        }

        try {
            $updated = new DateTimeImmutable($updatedAt);
        } catch (\Throwable) {
            return false;
        }

        return $updated->format('Y-m-d') === $now->format('Y-m-d');
    }

    private function now(): DateTimeImmutable
    {
        if ($this->nowFactory !== null) {
            $date = ($this->nowFactory)();
            if ($date instanceof DateTimeImmutable) {
                return $date;
            }
        }

        return new DateTimeImmutable;
    }
}
