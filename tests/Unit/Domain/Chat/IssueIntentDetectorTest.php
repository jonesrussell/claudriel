<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Domain\Chat;

use Claudriel\Domain\Chat\IssueIntentDetector;
use Claudriel\Domain\Chat\OrchestratorIntent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IssueIntentDetector::class)]
#[CoversClass(OrchestratorIntent::class)]
final class IssueIntentDetectorTest extends TestCase
{
    #[Test]
    public function detect_run_issue_intent(): void
    {
        $intent = IssueIntentDetector::detect('run issue #123');
        self::assertNotNull($intent);
        self::assertSame('run_issue', $intent->action);
        self::assertSame(123, $intent->params['issueNumber']);
    }

    #[Test]
    public function detect_work_on_issue_variant(): void
    {
        $intent = IssueIntentDetector::detect('work on issue #45');
        self::assertNotNull($intent);
        self::assertSame('run_issue', $intent->action);
        self::assertSame(45, $intent->params['issueNumber']);
    }

    #[Test]
    public function detect_start_issue_variant(): void
    {
        $intent = IssueIntentDetector::detect('start issue #7');
        self::assertNotNull($intent);
        self::assertSame('run_issue', $intent->action);
        self::assertSame(7, $intent->params['issueNumber']);
    }

    #[Test]
    public function detect_show_run_intent(): void
    {
        $intent = IssueIntentDetector::detect('show run abc-123-def');
        self::assertNotNull($intent);
        self::assertSame('show_run', $intent->action);
        self::assertSame('abc-123-def', $intent->params['runId']);
    }

    #[Test]
    public function detect_status_of_run_variant(): void
    {
        $intent = IssueIntentDetector::detect('status of run abc-123');
        self::assertNotNull($intent);
        self::assertSame('show_run', $intent->action);
        self::assertSame('abc-123', $intent->params['runId']);
    }

    #[Test]
    public function detect_list_runs_intent(): void
    {
        $intent = IssueIntentDetector::detect('list runs');
        self::assertNotNull($intent);
        self::assertSame('list_runs', $intent->action);
        self::assertSame([], $intent->params);
    }

    #[Test]
    public function detect_show_all_runs_variant(): void
    {
        $intent = IssueIntentDetector::detect('show all runs');
        self::assertNotNull($intent);
        self::assertSame('list_runs', $intent->action);
        self::assertSame([], $intent->params);
    }

    #[Test]
    public function detect_active_runs_variant(): void
    {
        $intent = IssueIntentDetector::detect('active runs');
        self::assertNotNull($intent);
        self::assertSame('list_runs', $intent->action);
        self::assertSame([], $intent->params);
    }

    #[Test]
    public function detect_show_diff_intent(): void
    {
        $intent = IssueIntentDetector::detect('diff for run abc-123');
        self::assertNotNull($intent);
        self::assertSame('show_diff', $intent->action);
        self::assertSame('abc-123', $intent->params['runId']);
    }

    #[Test]
    public function detect_show_diff_variant(): void
    {
        $intent = IssueIntentDetector::detect('show diff abc-123');
        self::assertNotNull($intent);
        self::assertSame('show_diff', $intent->action);
        self::assertSame('abc-123', $intent->params['runId']);
    }

    #[Test]
    public function detect_pause_run_intent(): void
    {
        $intent = IssueIntentDetector::detect('pause run abc-123');
        self::assertNotNull($intent);
        self::assertSame('pause_run', $intent->action);
        self::assertSame('abc-123', $intent->params['runId']);
    }

    #[Test]
    public function detect_resume_run_intent(): void
    {
        $intent = IssueIntentDetector::detect('resume run abc-123');
        self::assertNotNull($intent);
        self::assertSame('resume_run', $intent->action);
        self::assertSame('abc-123', $intent->params['runId']);
    }

    #[Test]
    public function detect_abort_run_intent(): void
    {
        $intent = IssueIntentDetector::detect('abort run abc-123');
        self::assertNotNull($intent);
        self::assertSame('abort_run', $intent->action);
        self::assertSame('abc-123', $intent->params['runId']);
    }

    #[Test]
    public function unrecognized_message_returns_null(): void
    {
        self::assertNull(IssueIntentDetector::detect('hello, how are you?'));
        self::assertNull(IssueIntentDetector::detect('what is the weather like?'));
        self::assertNull(IssueIntentDetector::detect('tell me about issue 123'));
    }

    #[Test]
    public function case_insensitive_detection(): void
    {
        $intent = IssueIntentDetector::detect('Run Issue #123');
        self::assertNotNull($intent);
        self::assertSame('run_issue', $intent->action);
        self::assertSame(123, $intent->params['issueNumber']);
    }
}
