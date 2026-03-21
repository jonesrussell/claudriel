<?php

declare(strict_types=1);

namespace Claudriel\Command;

use Claudriel\Ingestion\EventHandler;
use Claudriel\Ingestion\GitHubNotificationNormalizer;
use Claudriel\Support\GitHubTokenManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'claudriel:github:sync', description: 'Fetch GitHub notifications and ingest as events')]
final class GitHubSyncCommand extends Command
{
    public function __construct(
        private readonly GitHubTokenManagerInterface $tokenManager,
        private readonly EventHandler $eventHandler,
        private readonly GitHubNotificationNormalizer $normalizer,
        private readonly string $tenantId,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $token = $this->tokenManager->getValidAccessToken($this->tenantId);
        } catch (\RuntimeException $e) {
            $output->writeln("<comment>Skipping GitHub sync: {$e->getMessage()}</comment>");

            return Command::SUCCESS;
        }

        $notifications = $this->fetchNotifications($token);
        if ($notifications === null) {
            $output->writeln('<comment>GitHub API returned an error, will retry next cycle</comment>');

            return Command::SUCCESS;
        }

        $created = 0;
        foreach ($notifications as $raw) {
            $envelope = $this->normalizer->normalize($raw, $this->tenantId);
            $this->eventHandler->handle($envelope);
            $created++;
        }

        $output->writeln("<info>GitHub sync: {$created} new events from ".count($notifications).' notifications</info>');

        return Command::SUCCESS;
    }

    private function fetchNotifications(string $token): ?array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Authorization: Bearer {$token}\r\nUser-Agent: Claudriel\r\nAccept: application/vnd.github+json\r\n",
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents('https://api.github.com/notifications', false, $context);
        if ($response === false) {
            return null;
        }

        /** @phpstan-ignore isset.variable */
        $statusLine = $http_response_header[0] ?? '';
        if (str_contains($statusLine, '401')) {
            $this->tokenManager->markRevoked($this->tenantId);

            return null;
        }
        if (str_contains($statusLine, '403')) {
            return null;
        }

        return json_decode($response, true) ?: [];
    }
}
