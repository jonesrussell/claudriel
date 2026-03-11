<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Ingestion;

use Claudriel\Entity\Person;
use Claudriel\Ingestion\EventCategorizer;
use Claudriel\Support\AutomatedSenderDetector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;

final class EventCategorizerTest extends TestCase
{
    private EventCategorizer $categorizer;

    private EntityRepository $personRepo;

    protected function setUp(): void
    {
        $this->personRepo = new EntityRepository(
            new EntityType(id: 'person', label: 'Person', class: Person::class, keys: ['id' => 'pid', 'uuid' => 'uuid', 'label' => 'name']),
            new InMemoryStorageDriver,
            new EventDispatcher,
        );
        $this->categorizer = new EventCategorizer(new AutomatedSenderDetector, $this->personRepo);
    }

    public function test_calendar_event_categorized_as_schedule(): void
    {
        $result = $this->categorizer->categorize('google-calendar', 'calendar.event', ['title' => 'Team standup']);
        $this->assertSame('schedule', $result);
    }

    public function test_calendar_event_with_job_keyword_categorized_as_job_hunt(): void
    {
        $result = $this->categorizer->categorize('google-calendar', 'calendar.event', ['title' => 'Interview with Acme Corp']);
        $this->assertSame('job_hunt', $result);
    }

    public function test_gmail_with_job_subject_categorized_as_job_hunt(): void
    {
        $result = $this->categorizer->categorize('gmail', 'message.received', ['subject' => 'Your application was received', 'from_email' => 'recruiter@company.com']);
        $this->assertSame('job_hunt', $result);
    }

    public function test_gmail_with_job_body_categorized_as_job_hunt(): void
    {
        $result = $this->categorizer->categorize('gmail', 'message.received', ['subject' => 'Hello', 'body' => 'We have a position for you', 'from_email' => 'someone@example.com']);
        $this->assertSame('job_hunt', $result);
    }

    public function test_gmail_from_automated_sender_categorized_as_notification(): void
    {
        $result = $this->categorizer->categorize('gmail', 'message.received', [
            'subject' => 'Your token expired',
            'body' => 'Please regenerate.',
            'from_email' => 'noreply@github.com',
            'from_name' => 'GitHub',
        ]);
        $this->assertSame('notification', $result);
    }

    public function test_gmail_from_known_person_categorized_as_people(): void
    {
        $this->personRepo->save(new Person([
            'email' => 'jane@example.com',
            'name' => 'Jane',
            'tier' => 'contact',
            'tenant_id' => 'user-1',
            'source' => 'gmail',
        ]));

        $result = $this->categorizer->categorize('gmail', 'message.received', [
            'subject' => 'Lunch tomorrow?',
            'body' => 'Want to grab lunch?',
            'from_email' => 'jane@example.com',
        ]);
        $this->assertSame('people', $result);
    }

    public function test_gmail_from_unknown_sender_categorized_as_triage(): void
    {
        $result = $this->categorizer->categorize('gmail', 'message.received', [
            'subject' => 'Lunch tomorrow?',
            'body' => 'Want to grab lunch?',
            'from_email' => 'stranger@example.com',
        ]);
        $this->assertSame('triage', $result);
    }

    public function test_unknown_source_categorized_as_notification(): void
    {
        $result = $this->categorizer->categorize('webhook', 'alert', []);
        $this->assertSame('notification', $result);
    }

    public function test_categorization_is_case_insensitive(): void
    {
        $result = $this->categorizer->categorize('gmail', 'message.received', ['subject' => 'JOB APPLICATION Update', 'from_email' => 'someone@example.com']);
        $this->assertSame('job_hunt', $result);
    }

    public function test_gmail_without_person_repo_falls_to_triage(): void
    {
        $categorizer = new EventCategorizer(new AutomatedSenderDetector);
        $result = $categorizer->categorize('gmail', 'message.received', [
            'subject' => 'Hello',
            'body' => 'Nice day',
            'from_email' => 'someone@example.com',
        ]);
        $this->assertSame('triage', $result);
    }

    public function test_job_keywords_take_priority_over_automated_sender(): void
    {
        $result = $this->categorizer->categorize('gmail', 'message.received', [
            'subject' => 'Your job application was received',
            'body' => 'Thanks for applying',
            'from_email' => 'noreply@indeed.com',
        ]);
        $this->assertSame('job_hunt', $result);
    }
}
