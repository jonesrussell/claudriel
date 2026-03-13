<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Domain\Chat\AnthropicChatClient;
use Claudriel\Domain\Chat\ChatSystemPromptBuilder;
use Claudriel\Domain\Chat\SidecarChatClient;
use Claudriel\Domain\DayBrief\Assembler\DayBriefAssembler;
use Claudriel\Entity\ChatMessage;
use Claudriel\Entity\Workspace;
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
        if (! $userMsg instanceof ChatMessage) {
            return new SsrResponse(
                content: json_encode(['error' => 'Message not found']),
                statusCode: 404,
                headers: ['Content-Type' => 'application/json'],
            );
        }
        $sessionUuid = $userMsg->get('session_uuid');

        $localActionResponse = $this->handleLocalAction($userMsg, $msgStorage);
        if ($localActionResponse instanceof StreamedResponse) {
            return $localActionResponse;
        }

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
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_write_close();
                }
                $this->streamTokens($sessionUuid, $apiKey, $msgStorage);
            },
            200,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'X-Accel-Buffering' => 'no',
            ],
        );
    }

    private function handleLocalAction(ChatMessage $userMsg, mixed $msgStorage): ?StreamedResponse
    {
        $content = trim((string) $userMsg->get('content'));
        $workspaceDeletes = $this->extractWorkspaceDeletionNames($content);
        $workspaceStorage = $this->entityTypeManager->getStorage('workspace');

        if ($workspaceDeletes !== []) {
            $deleted = [];
            $missing = [];

            foreach ($workspaceDeletes as $workspaceName) {
                $existingIds = $workspaceStorage->getQuery()->condition('name', $workspaceName)->execute();
                if ($existingIds === []) {
                    $missing[] = $workspaceName;
                    continue;
                }

                $workspace = $workspaceStorage->load(reset($existingIds));
                if ($workspace instanceof Workspace) {
                    $workspaceStorage->delete([$workspace]);
                    $deleted[] = (string) $workspace->get('name');
                }
            }

            $responseText = $this->buildWorkspaceDeletionResponse($deleted, $missing);

            return $this->buildLocalActionResponse($userMsg, $msgStorage, $responseText);
        }

        $workspaceName = $this->extractWorkspaceName($content);

        if ($workspaceName === null) {
            return null;
        }

        $existingIds = $workspaceStorage->getQuery()->condition('name', $workspaceName)->execute();

        if ($existingIds !== []) {
            $existing = $workspaceStorage->load(reset($existingIds));
            $responseText = sprintf(
                'The workspace "%s" already exists.',
                (string) (($existing instanceof Workspace ? $existing->get('name') : null) ?? $workspaceName),
            );
        } else {
            $workspace = new Workspace([
                'name' => $workspaceName,
                'description' => '',
            ]);
            $workspaceStorage->save($workspace);

            $responseText = sprintf(
                'Created the Claudriel workspace "%s". Refresh the sidebar if it is not visible yet.',
                $workspaceName,
            );
        }

        return $this->buildLocalActionResponse($userMsg, $msgStorage, $responseText);
    }

    private function buildLocalActionResponse(ChatMessage $userMsg, mixed $msgStorage, string $responseText): StreamedResponse
    {
        return new StreamedResponse(
            function () use ($userMsg, $msgStorage, $responseText): void {
                echo "retry: 3000\n\n";

                $assistantMsg = new ChatMessage([
                    'uuid' => $this->generateUuid(),
                    'session_uuid' => $userMsg->get('session_uuid'),
                    'role' => 'assistant',
                    'content' => $responseText,
                    'created_at' => (new \DateTimeImmutable)->format('c'),
                ]);
                $msgStorage->save($assistantMsg);

                $data = json_encode(['done' => true, 'full_response' => $responseText], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                echo "event: chat-done\ndata: {$data}\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            },
            200,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'X-Accel-Buffering' => 'no',
            ],
        );
    }

    /**
     * @return list<string>
     */
    private function extractWorkspaceDeletionNames(string $message): array
    {
        $normalized = str_replace(["\u{201C}", "\u{201D}", "\u{2018}", "\u{2019}"], ['"', '"', "'", "'"], $message);
        $patterns = [
            '/\b(?:delete|remove)\b.*?\bworkspace\b(?:s)?\s+(.+)$/iu',
            '/\b(?:delete|remove)\b\s+(.+?)\s+\bworkspace\b(?:s)?$/iu',
        ];

        $targetSegment = null;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized, $matches)) {
                $targetSegment = trim($matches[1]);
                break;
            }
        }

        if ($targetSegment === null || $targetSegment === '') {
            return [];
        }

        $parts = preg_split('/\s*(?:,|and)\s*/iu', $targetSegment) ?: [];
        $names = [];

        foreach ($parts as $part) {
            $name = trim($part);
            $name = trim($name, " \t\n\r\0\x0B\"'.,!?;:");
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param list<string> $deleted
     * @param list<string> $missing
     */
    private function buildWorkspaceDeletionResponse(array $deleted, array $missing): string
    {
        if ($deleted !== [] && $missing === []) {
            return sprintf(
                'Deleted %s.',
                $this->formatWorkspaceNameList($deleted),
            );
        }

        if ($deleted === [] && $missing !== []) {
            return sprintf(
                'Could not find %s.',
                $this->formatWorkspaceNameList($missing),
            );
        }

        if ($deleted !== [] && $missing !== []) {
            return sprintf(
                'Deleted %s. Could not find %s.',
                $this->formatWorkspaceNameList($deleted),
                $this->formatWorkspaceNameList($missing),
            );
        }

        return 'No workspace names were recognized in that delete request.';
    }

    /**
     * @param list<string> $names
     */
    private function formatWorkspaceNameList(array $names): string
    {
        $quoted = array_map(static fn (string $name): string => sprintf('"%s"', $name), $names);

        return match (count($quoted)) {
            0 => 'no workspaces',
            1 => $quoted[0],
            2 => $quoted[0].' and '.$quoted[1],
            default => implode(', ', array_slice($quoted, 0, -1)).', and '.$quoted[array_key_last($quoted)],
        };
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

        // Try sidecar first (provides Gmail/Calendar via Claude Code MCP)
        $sidecarUrl = $_ENV['SIDECAR_URL'] ?? getenv('SIDECAR_URL') ?: '';
        $sidecarKey = $_ENV['CLAUDRIEL_SIDECAR_KEY'] ?? getenv('CLAUDRIEL_SIDECAR_KEY') ?: '';
        $useSidecar = false;
        $sidecarClient = null;

        if ($sidecarUrl !== '' && $sidecarKey !== '') {
            $sidecarClient = new SidecarChatClient($sidecarUrl, $sidecarKey);
            $useSidecar = $sidecarClient->isAvailable();
        }

        // Build system prompt (tool instructions only when sidecar is available)
        $projectRoot = $this->resolveProjectRoot();
        $promptBuilder = $this->buildPromptBuilder($projectRoot);
        $systemPrompt = $promptBuilder->build(hasToolAccess: $useSidecar);

        $onToken = function (string $token): void {
            $data = json_encode(['token' => $token], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            echo "event: chat-token\ndata: {$data}\n\n";
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        };

        $onDone = function (string $fullResponse) use ($sessionUuid, $msgStorage): void {
            $assistantMsg = new ChatMessage([
                'uuid' => $this->generateUuid(),
                'session_uuid' => $sessionUuid,
                'role' => 'assistant',
                'content' => $fullResponse,
                'created_at' => (new \DateTimeImmutable)->format('c'),
            ]);
            $msgStorage->save($assistantMsg);

            $data = json_encode(['done' => true, 'full_response' => $fullResponse], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            echo "event: chat-done\ndata: {$data}\n\n";
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        };

        $onError = function (string $error): void {
            $data = json_encode(['error' => $error], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            echo "event: chat-error\ndata: {$data}\n\n";
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        };

        if ($useSidecar) {
            $sidecarClient->stream(
                $systemPrompt,
                $apiMessages,
                $onToken,
                $onDone,
                $onError,
                sessionId: $sessionUuid,
            );
        } else {
            // Fallback: direct Anthropic API (no Gmail/Calendar)
            $model = $_ENV['ANTHROPIC_MODEL'] ?? getenv('ANTHROPIC_MODEL') ?: 'claude-sonnet-4-20250514';
            $client = new AnthropicChatClient($apiKey, $model);

            $client->stream(
                $systemPrompt,
                $apiMessages,
                onToken: $onToken,
                onDone: $onDone,
                onError: $onError,
            );
        }
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
            if (is_file($dir.'/composer.json')) {
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

        $personRepo = null;
        try {
            $personRepo = new StorageRepositoryAdapter($this->entityTypeManager->getStorage('person'));
        } catch (\Throwable) {
        }

        $assembler = new DayBriefAssembler($eventRepo, $commitmentRepo, $driftDetector, $personRepo, $skillRepo);

        return new ChatSystemPromptBuilder($assembler, $projectRoot);
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

    private function extractWorkspaceName(string $message): ?string
    {
        $normalized = str_replace(["\u{201C}", "\u{201D}", "\u{2018}", "\u{2019}"], ['"', '"', "'", "'"], $message);
        $patterns = [
            '/\bcreate\b.*?\bworkspace\b.*?\b(?:named|called)\s+["\']?([^"\']+)["\']?/iu',
            '/\bcreate\b.*?\bworkspace\b\s+["\']?([^"\']+)["\']?/iu',
            '/\bnew\b.*?\bworkspace\b.*?\b(?:named|called)\s+["\']?([^"\']+)["\']?/iu',
        ];

        foreach ($patterns as $pattern) {
            if (! preg_match($pattern, $normalized, $matches)) {
                continue;
            }

            $name = trim($matches[1]);
            $name = trim($name, " \t\n\r\0\x0B.,!?;:");

            if ($name !== '') {
                return $name;
            }
        }

        return null;
    }
}
