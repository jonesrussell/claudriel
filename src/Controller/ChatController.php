<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Entity\ChatMessage;
use Claudriel\Entity\ChatSession;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrResponse;

/**
 * Chat interface controller.
 *
 * HttpKernel instantiates as: new ChatController($entityTypeManager, $twig)
 */
final class ChatController
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly mixed $twig = null,
    ) {}

    /**
     * GET /chat — render the chat UI.
     */
    public function index(array $params = [], array $query = [], mixed $account = null, mixed $httpRequest = null): SsrResponse
    {
        $apiKey = $this->getApiKey();

        // Load recent sessions
        $sessionStorage = $this->entityTypeManager->getStorage('chat_session');
        $sessionIds = $sessionStorage->getQuery()->execute();
        $allSessions = $sessionStorage->loadMultiple($sessionIds);

        // Sort by created_at descending, take 10
        usort($allSessions, function ($a, $b) {
            return ($b->get('created_at') ?? '') <=> ($a->get('created_at') ?? '');
        });
        $sessions = array_slice($allSessions, 0, 10);

        $twigSessions = [];
        foreach ($sessions as $session) {
            $twigSessions[] = [
                'uuid' => $session->get('uuid'),
                'title' => $session->get('title') ?? 'New Chat',
                'created_at' => $session->get('created_at'),
            ];
        }

        if ($this->twig !== null) {
            $html = $this->twig->render('chat.html.twig', [
                'sessions' => $twigSessions,
                'api_configured' => $apiKey !== null,
            ]);

            return new SsrResponse(
                content: $html,
                statusCode: 200,
                headers: ['Content-Type' => 'text/html; charset=UTF-8'],
            );
        }

        return new SsrResponse(
            content: json_encode(['sessions' => $twigSessions, 'api_configured' => $apiKey !== null]),
            statusCode: 200,
            headers: ['Content-Type' => 'application/json'],
        );
    }

    /**
     * GET /api/chat/sessions/{uuid}/messages — load messages for a session.
     */
    public function messages(array $params = [], array $query = [], mixed $account = null, mixed $httpRequest = null): SsrResponse
    {
        $uuid = $params['uuid'] ?? '';
        $sessionStorage = $this->entityTypeManager->getStorage('chat_session');
        $sessionIds = $sessionStorage->getQuery()->condition('uuid', $uuid)->execute();

        if (empty($sessionIds)) {
            return new SsrResponse(
                content: json_encode(['error' => 'Session not found']),
                statusCode: 404,
                headers: ['Content-Type' => 'application/json'],
            );
        }

        $messageStorage = $this->entityTypeManager->getStorage('chat_message');
        $messageIds = $messageStorage->getQuery()->condition('session_uuid', $uuid)->execute();
        $allMessages = $messageStorage->loadMultiple($messageIds);

        usort($allMessages, function ($a, $b) {
            return ($a->get('created_at') ?? '') <=> ($b->get('created_at') ?? '');
        });

        $result = [];
        foreach ($allMessages as $msg) {
            $result[] = [
                'role' => $msg->get('role'),
                'content' => $msg->get('content'),
            ];
        }

        return new SsrResponse(
            content: json_encode(['messages' => $result]),
            statusCode: 200,
            headers: ['Content-Type' => 'application/json'],
        );
    }

    /**
     * POST /api/chat/send — send a message and get the assistant response.
     */
    public function send(array $params = [], array $query = [], mixed $account = null, mixed $httpRequest = null): SsrResponse
    {
        $raw = method_exists($httpRequest, 'getContent') ? $httpRequest->getContent() : '';
        $body = json_decode($raw, true) ?? [];
        $message = trim($body['message'] ?? '');
        $sessionUuid = $body['session_id'] ?? null;

        if ($message === '') {
            return new SsrResponse(
                content: json_encode(['error' => 'Message required']),
                statusCode: 422,
                headers: ['Content-Type' => 'application/json'],
            );
        }

        $apiKey = $this->getApiKey();
        if ($apiKey === null) {
            return new SsrResponse(
                content: json_encode(['error' => 'Chat not configured. Set ANTHROPIC_API_KEY in your environment.']),
                statusCode: 503,
                headers: ['Content-Type' => 'application/json'],
            );
        }

        $sessionStorage = $this->entityTypeManager->getStorage('chat_session');
        $messageStorage = $this->entityTypeManager->getStorage('chat_message');

        // Find or create session
        $session = null;
        if ($sessionUuid !== null) {
            $ids = $sessionStorage->getQuery()->condition('uuid', $sessionUuid)->execute();
            if (! empty($ids)) {
                $session = $sessionStorage->load(reset($ids));
            }
        }

        if ($session === null) {
            $session = new ChatSession([
                'uuid' => $this->generateUuid(),
                'title' => mb_substr($message, 0, 60),
                'created_at' => (new \DateTimeImmutable)->format('c'),
            ]);
            $sessionStorage->save($session);
        }

        $sessionUuid = $session->get('uuid');

        // Save user message
        $userMsg = new ChatMessage([
            'uuid' => $this->generateUuid(),
            'session_uuid' => $sessionUuid,
            'role' => 'user',
            'content' => $message,
            'created_at' => (new \DateTimeImmutable)->format('c'),
        ]);
        $messageStorage->save($userMsg);

        // Return message ID for streaming via /stream/chat/{messageId}
        return new SsrResponse(
            content: json_encode([
                'message_id' => $userMsg->get('uuid'),
                'session_id' => $sessionUuid,
            ]),
            statusCode: 200,
            headers: ['Content-Type' => 'application/json'],
        );
    }

    private function getApiKey(): ?string
    {
        $key = getenv('ANTHROPIC_API_KEY');

        return is_string($key) && $key !== '' ? $key : null;
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
