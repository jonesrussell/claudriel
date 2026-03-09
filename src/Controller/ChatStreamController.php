<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Domain\Chat\AnthropicChatClient;
use Claudriel\Domain\Chat\ChatSystemPromptBuilder;
use Claudriel\Domain\DayBrief\Assembler\DayBriefAssembler;
use Claudriel\Entity\ChatMessage;
use Claudriel\Support\DriftDetector;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrResponse;

final class ChatStreamController
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly mixed $twig = null,
    ) {}

    /**
     * GET /stream/chat/{messageId} — SSE stream of Anthropic response tokens.
     */
    public function stream(array $params = [], array $query = [], mixed $account = null, mixed $httpRequest = null): StreamedResponse|SsrResponse
    {
        $messageId = $params['messageId'] ?? '';

        // Find the user message
        $msgStorage = $this->entityTypeManager->getStorage('chat_message');
        $ids = $msgStorage->getQuery()->condition('uuid', $messageId)->execute();
        if ($ids === []) {
            return new SsrResponse(
                content: json_encode(['error' => 'Message not found']),
                statusCode: 404,
                headers: ['Content-Type' => 'application/json'],
            );
        }

        $userMsg = $msgStorage->load(reset($ids));
        $sessionUuid = $userMsg->get('session_uuid');

        // Check API key
        $apiKey = $this->getApiKey();
        if ($apiKey === null) {
            return new SsrResponse(
                content: json_encode(['error' => 'Chat not configured. Set ANTHROPIC_API_KEY.']),
                statusCode: 503,
                headers: ['Content-Type' => 'application/json'],
            );
        }

        return new StreamedResponse(
            function () use ($sessionUuid, $apiKey, $msgStorage): void {
                $this->streamTokens($sessionUuid, $apiKey, $msgStorage);
            },
            200,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'Connection' => 'keep-alive',
                'X-Accel-Buffering' => 'no',
            ],
        );
    }

    private function streamTokens(string $sessionUuid, string $apiKey, mixed $msgStorage): void
    {
        echo "retry: 3000\n\n";
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();

        // Load conversation history
        $allMsgIds = $msgStorage->getQuery()->execute();
        $allMessages = $msgStorage->loadMultiple($allMsgIds);
        $sessionMessages = [];
        foreach ($allMessages as $msg) {
            if ($msg->get('session_uuid') === $sessionUuid) {
                $sessionMessages[] = $msg;
            }
        }
        usort($sessionMessages, fn ($a, $b) => ($a->get('created_at') ?? '') <=> ($b->get('created_at') ?? ''));

        $apiMessages = array_map(
            fn ($m) => ['role' => $m->get('role'), 'content' => $m->get('content')],
            $sessionMessages,
        );

        // Build system prompt
        $projectRoot = $this->resolveProjectRoot();
        $promptBuilder = $this->buildPromptBuilder($projectRoot);
        $systemPrompt = $promptBuilder->build();

        // Stream from Anthropic
        $model = $_ENV['ANTHROPIC_MODEL'] ?? getenv('ANTHROPIC_MODEL') ?: 'claude-sonnet-4-20250514';
        $client = new AnthropicChatClient($apiKey, $model);

        $client->stream(
            $systemPrompt,
            $apiMessages,
            onToken: function (string $token): void {
                $data = json_encode(['token' => $token], JSON_THROW_ON_ERROR);
                echo "event: chat-token\ndata: {$data}\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            },
            onDone: function (string $fullResponse) use ($sessionUuid, $msgStorage): void {
                // Save assistant message
                $assistantMsg = new ChatMessage([
                    'uuid' => $this->generateUuid(),
                    'session_uuid' => $sessionUuid,
                    'role' => 'assistant',
                    'content' => $fullResponse,
                    'created_at' => (new \DateTimeImmutable())->format('c'),
                ]);
                $msgStorage->save($assistantMsg);

                $data = json_encode(['done' => true, 'full_response' => $fullResponse], JSON_THROW_ON_ERROR);
                echo "event: chat-done\ndata: {$data}\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            },
            onError: function (string $error): void {
                $data = json_encode(['error' => $error], JSON_THROW_ON_ERROR);
                echo "event: chat-error\ndata: {$data}\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            },
        );
    }

    private function getApiKey(): ?string
    {
        $key = $_ENV['ANTHROPIC_API_KEY'] ?? getenv('ANTHROPIC_API_KEY') ?: null;
        return is_string($key) && $key !== '' ? $key : null;
    }

    private function resolveProjectRoot(): string
    {
        $root = getenv('CLAUDRIEL_ROOT');
        if (is_string($root) && $root !== '' && is_dir($root)) {
            return $root;
        }
        $dir = __DIR__;
        while ($dir !== '/' && $dir !== '') {
            if (is_file($dir . '/composer.json')) {
                return $dir;
            }
            $dir = dirname($dir);
        }
        return getcwd() ?: '/tmp';
    }

    private function buildPromptBuilder(string $projectRoot): ChatSystemPromptBuilder
    {
        $eventStorage = $this->entityTypeManager->getStorage('mc_event');
        $commitmentStorage = $this->entityTypeManager->getStorage('commitment');
        $skillStorage = $this->entityTypeManager->getStorage('skill');

        $eventRepo = new StorageRepositoryAdapter($eventStorage);
        $commitmentRepo = new StorageRepositoryAdapter($commitmentStorage);
        $skillRepo = new StorageRepositoryAdapter($skillStorage);
        $driftDetector = new DriftDetector($commitmentRepo);

        $assembler = new DayBriefAssembler($eventRepo, $commitmentRepo, $driftDetector, $skillRepo);
        return new ChatSystemPromptBuilder($assembler, $projectRoot);
    }

    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff), random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff),
        );
    }
}
