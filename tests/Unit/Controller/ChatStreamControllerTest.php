<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Controller;

use Claudriel\Access\AuthenticatedAccount;
use Claudriel\Controller\ChatStreamController;
use Claudriel\Domain\Chat\SubprocessChatClient;
use Claudriel\Entity\Account;
use Claudriel\Entity\ChatMessage;
use Claudriel\Entity\ChatSession;
use Claudriel\Entity\Commitment;
use Claudriel\Entity\McEvent;
use Claudriel\Entity\Person;
use Claudriel\Entity\Skill;
use Claudriel\Entity\Workspace;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\SSR\SsrResponse;

final class ChatStreamControllerTest extends TestCase
{
    public function test_returns404_for_nonexistent_message(): void
    {
        $etm = $this->buildEntityTypeManager();
        $controller = new ChatStreamController($etm);

        $response = $controller->stream(
            ['messageId' => 'nonexistent-uuid'],
            [],
            null,
            null,
        );

        self::assertInstanceOf(SsrResponse::class, $response);
        self::assertSame(404, $response->statusCode);
    }

    public function test_returns503_when_api_key_missing(): void
    {
        $originalKey = getenv('ANTHROPIC_API_KEY');
        putenv('ANTHROPIC_API_KEY');
        unset($_ENV['ANTHROPIC_API_KEY']);

        $etm = $this->buildEntityTypeManager();

        // Create a session and message
        $sessionStorage = $etm->getStorage('chat_session');
        $session = new ChatSession(['uuid' => 'sess-1', 'title' => 'Test', 'created_at' => date('c')]);
        $sessionStorage->save($session);

        $msgStorage = $etm->getStorage('chat_message');
        $msg = new ChatMessage([
            'uuid' => 'msg-1',
            'session_uuid' => 'sess-1',
            'role' => 'user',
            'content' => 'hello',
            'created_at' => date('c'),
        ]);
        $msgStorage->save($msg);

        $controller = new ChatStreamController($etm);
        $response = $controller->stream(['messageId' => 'msg-1'], [], null, null);

        self::assertInstanceOf(SsrResponse::class, $response);
        self::assertSame(503, $response->statusCode);

        if ($originalKey !== false) {
            putenv("ANTHROPIC_API_KEY={$originalKey}");
        }
    }

    public function test_stream_forwards_sanitized_progress_events_from_subprocess(): void
    {
        $originalKey = getenv('ANTHROPIC_API_KEY');
        $originalSecret = getenv('AGENT_INTERNAL_SECRET');
        $originalApiUrl = getenv('CLAUDRIEL_API_URL');
        putenv('ANTHROPIC_API_KEY=test-key');
        putenv('AGENT_INTERNAL_SECRET=test-secret-that-is-at-least-32-bytes-long');
        putenv('CLAUDRIEL_API_URL=http://localhost:8088');

        $etm = $this->buildEntityTypeManager();

        $sessionStorage = $etm->getStorage('chat_session');
        $sessionStorage->save(new ChatSession(['uuid' => 'sess-6', 'title' => 'Telemetry', 'created_at' => date('c')]));

        $msgStorage = $etm->getStorage('chat_message');
        $msgStorage->save(new ChatMessage([
            'uuid' => 'msg-6',
            'session_uuid' => 'sess-6',
            'role' => 'user',
            'content' => 'What is on my calendar today?',
            'created_at' => date('c'),
        ]));

        // Create a mock script that emits progress + token events
        $script = sys_get_temp_dir().'/mock_agent_progress_'.uniqid().'.php';
        file_put_contents($script, <<<'PHP'
        <?php
        // Read stdin (the request JSON) and discard
        file_get_contents('php://stdin');
        echo json_encode(['event' => 'tool_call', 'tool' => 'calendar_list', 'args' => []]) . "\n";
        echo json_encode(['event' => 'tool_result', 'tool' => 'calendar_list', 'result' => ['items' => []]]) . "\n";
        echo json_encode(['event' => 'message', 'content' => 'Today looks clear.']) . "\n";
        echo json_encode(['event' => 'done']) . "\n";
        PHP);

        $controller = new ChatStreamController(
            $etm,
            subprocessClientFactory: static function () use ($script) {
                return new SubprocessChatClient(
                    command: [PHP_BINARY, $script],
                    timeoutSeconds: 10,
                );
            },
        );

        $response = $controller->stream(['messageId' => 'msg-6'], [], null, null);

        self::assertInstanceOf(StreamedResponse::class, $response);

        ob_start();
        ob_start();
        $callback = $response->getCallback();
        self::assertIsCallable($callback);
        $callback();
        ob_end_flush();
        $output = ob_get_clean();

        self::assertIsString($output);
        self::assertStringContainsString('event: chat-progress', $output);
        self::assertStringContainsString('event: chat-token', $output);
        self::assertStringContainsString('event: chat-done', $output);

        unlink($script);

        if ($originalKey !== false) {
            putenv("ANTHROPIC_API_KEY={$originalKey}");
        } else {
            putenv('ANTHROPIC_API_KEY');
        }
        if ($originalSecret !== false) {
            putenv("AGENT_INTERNAL_SECRET={$originalSecret}");
        } else {
            putenv('AGENT_INTERNAL_SECRET');
        }
        if ($originalApiUrl !== false) {
            putenv("CLAUDRIEL_API_URL={$originalApiUrl}");
        } else {
            putenv('CLAUDRIEL_API_URL');
        }
    }

    public function test_stream_uses_tenant_uuid_not_entity_id_for_account_id(): void
    {
        $originalKey = getenv('ANTHROPIC_API_KEY');
        $originalSecret = getenv('AGENT_INTERNAL_SECRET');
        $originalApiUrl = getenv('CLAUDRIEL_API_URL');
        putenv('ANTHROPIC_API_KEY=test-key');
        putenv('AGENT_INTERNAL_SECRET=test-secret-that-is-at-least-32-bytes-long');
        putenv('CLAUDRIEL_API_URL=http://localhost:8088');

        $etm = $this->buildEntityTypeManager();

        $tenantUuid = 'acct-uuid-'.uniqid();

        $sessionStorage = $etm->getStorage('chat_session');
        $sessionStorage->save(new ChatSession(['uuid' => 'sess-acctid', 'title' => 'AccountId Test', 'created_at' => date('c')]));

        $msgStorage = $etm->getStorage('chat_message');
        $msgStorage->save(new ChatMessage([
            'uuid' => 'msg-acctid',
            'session_uuid' => 'sess-acctid',
            'role' => 'user',
            'content' => 'Check my calendar',
            'created_at' => date('c'),
            'tenant_id' => $tenantUuid,
        ]));

        // Create an Account entity with a sequential id different from the tenant UUID
        $account = new Account([
            'aid' => 42,
            'uuid' => $tenantUuid,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'status' => 'active',
            'email_verified_at' => date('c'),
        ]);

        // Mock script that writes stdin to a temp file so we can inspect the payload
        $stdinCapture = sys_get_temp_dir().'/stdin_capture_'.uniqid().'.json';
        $script = sys_get_temp_dir().'/mock_agent_acctid_'.uniqid().'.php';
        file_put_contents($script, <<<PHP
        <?php
        \$stdin = file_get_contents('php://stdin');
        file_put_contents('{$stdinCapture}', \$stdin);
        echo json_encode(['event' => 'message', 'content' => 'Done.']) . "\\n";
        echo json_encode(['event' => 'done']) . "\\n";
        PHP);

        $controller = new ChatStreamController(
            $etm,
            subprocessClientFactory: static function () use ($script) {
                return new SubprocessChatClient(
                    command: [PHP_BINARY, $script],
                    timeoutSeconds: 10,
                );
            },
        );

        $response = $controller->stream(['messageId' => 'msg-acctid'], [], new AuthenticatedAccount($account), null);
        self::assertInstanceOf(StreamedResponse::class, $response);

        ob_start();
        ob_start();
        $callback = $response->getCallback();
        self::assertIsCallable($callback);
        $callback();
        ob_end_flush();
        ob_get_clean();

        // Verify the subprocess received the tenant UUID, not the sequential entity ID
        self::assertFileExists($stdinCapture, 'Subprocess should have received stdin');
        $payload = json_decode((string) file_get_contents($stdinCapture), true);
        self::assertIsArray($payload);
        self::assertSame($tenantUuid, $payload['account_id'], 'account_id must be the tenant UUID, not the sequential entity ID');
        self::assertNotSame('42', $payload['account_id'], 'account_id must not be the sequential entity ID');

        @unlink($script);
        @unlink($stdinCapture);

        if ($originalKey !== false) {
            putenv("ANTHROPIC_API_KEY={$originalKey}");
        } else {
            putenv('ANTHROPIC_API_KEY');
        }
        if ($originalSecret !== false) {
            putenv("AGENT_INTERNAL_SECRET={$originalSecret}");
        } else {
            putenv('AGENT_INTERNAL_SECRET');
        }
        if ($originalApiUrl !== false) {
            putenv("CLAUDRIEL_API_URL={$originalApiUrl}");
        } else {
            putenv('CLAUDRIEL_API_URL');
        }
    }

    public function test_stream_fails_closed_for_mismatched_workspace_scope(): void
    {
        $originalKey = getenv('ANTHROPIC_API_KEY');
        putenv('ANTHROPIC_API_KEY');
        unset($_ENV['ANTHROPIC_API_KEY']);

        $etm = $this->buildEntityTypeManager();
        $workspaceStorage = $etm->getStorage('workspace');
        $workspaceStorage->save(new Workspace(['uuid' => 'workspace-a', 'name' => 'Workspace A', 'tenant_id' => 'default']));
        $workspaceStorage->save(new Workspace(['uuid' => 'workspace-b', 'name' => 'Workspace B', 'tenant_id' => 'default']));

        $sessionStorage = $etm->getStorage('chat_session');
        $sessionStorage->save(new ChatSession(['uuid' => 'sess-scope', 'title' => 'Scope Test', 'created_at' => date('c'), 'tenant_id' => 'default', 'workspace_id' => 'workspace-a']));

        $msgStorage = $etm->getStorage('chat_message');
        $msgStorage->save(new ChatMessage([
            'uuid' => 'msg-scope',
            'session_uuid' => 'sess-scope',
            'role' => 'user',
            'content' => 'hello',
            'created_at' => date('c'),
            'tenant_id' => 'default',
            'workspace_id' => 'workspace-a',
        ]));

        $controller = new ChatStreamController($etm);
        $request = Request::create('/stream/chat/msg-scope?workspace_uuid=workspace-b', 'GET');
        $response = $controller->stream(['messageId' => 'msg-scope'], ['workspace_uuid' => 'workspace-b'], null, $request);

        self::assertInstanceOf(SsrResponse::class, $response);
        self::assertSame(404, $response->statusCode);

        if ($originalKey !== false) {
            putenv("ANTHROPIC_API_KEY={$originalKey}");
        }
    }

    private function buildEntityTypeManager(): EntityTypeManager
    {
        $db = DBALDatabase::createSqlite(':memory:');
        $dispatcher = new EventDispatcher;
        $etm = new EntityTypeManager($dispatcher, function ($def) use ($db, $dispatcher) {
            (new SqlSchemaHandler($def, $db))->ensureTable();

            return new SqlEntityStorage($def, $db, $dispatcher);
        });
        foreach ($this->entityTypes() as $type) {
            $etm->registerEntityType($type);
        }

        return $etm;
    }

    /** @return list<EntityType> */
    private function entityTypes(): array
    {
        return [
            new EntityType(id: 'mc_event', label: 'Event', class: McEvent::class, keys: ['id' => 'eid', 'uuid' => 'uuid']),
            new EntityType(id: 'commitment', label: 'Commitment', class: Commitment::class, keys: ['id' => 'cid', 'uuid' => 'uuid', 'label' => 'title']),
            new EntityType(id: 'person', label: 'Person', class: Person::class, keys: ['id' => 'pid', 'uuid' => 'uuid', 'label' => 'name']),
            new EntityType(id: 'skill', label: 'Skill', class: Skill::class, keys: ['id' => 'sid', 'uuid' => 'uuid', 'label' => 'name']),
            new EntityType(id: 'chat_session', label: 'Chat Session', class: ChatSession::class, keys: ['id' => 'csid', 'uuid' => 'uuid', 'label' => 'title']),
            new EntityType(id: 'chat_message', label: 'Chat Message', class: ChatMessage::class, keys: ['id' => 'cmid', 'uuid' => 'uuid']),
            new EntityType(id: 'workspace', label: 'Workspace', class: Workspace::class, keys: ['id' => 'wid', 'uuid' => 'uuid', 'label' => 'name']),
        ];
    }
}
