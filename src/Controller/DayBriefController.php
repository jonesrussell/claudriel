<?php

declare(strict_types=1);

namespace MyClaudia\Controller;

use MyClaudia\DayBrief\BriefSessionStore;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrResponse;

/**
 * Web controller for the daily brief JSON endpoint.
 *
 * The HttpKernel instantiates app controllers as new $class($entityTypeManager, $twig)
 * and expects SsrResponse with public content/statusCode/headers properties.
 */
final class DayBriefController
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly mixed $twig = null,
    ) {}

    public function show(): SsrResponse
    {
        $storageDir   = getenv('MYCLAUDIA_STORAGE') ?: sys_get_temp_dir() . '/myclaudia';
        $sessionStore = new BriefSessionStore($storageDir . '/brief-session.txt');
        $since        = $sessionStore->getLastBriefAt() ?? new \DateTimeImmutable('-24 hours');

        $eventStorage = $this->entityTypeManager->getStorage('mc_event');
        $allEventIds  = $eventStorage->getQuery()->execute();
        $allEvents    = $eventStorage->loadMultiple($allEventIds);

        $recentEvents = array_values(array_filter(
            $allEvents,
            fn ($e) => new \DateTimeImmutable($e->get('occurred') ?? 'now') >= $since,
        ));

        $eventsBySource = [];
        $people = [];
        foreach ($recentEvents as $event) {
            $source = $event->get('source') ?? 'unknown';
            $eventsBySource[$source][] = $event->toArray();
            $payload = json_decode($event->get('payload') ?? '{}', true) ?? [];
            $email   = $payload['from_email'] ?? null;
            $name    = $payload['from_name'] ?? null;
            if (is_string($email) && $email !== '') {
                $people[$email] = $name ?? $email;
            }
        }

        $commitmentStorage  = $this->entityTypeManager->getStorage('commitment');
        $allCommitmentIds   = $commitmentStorage->getQuery()->execute();
        $allCommitments     = $commitmentStorage->loadMultiple($allCommitmentIds);
        $pendingCommitments = array_values(array_filter(
            $allCommitments,
            fn ($c) => $c->get('status') === 'pending',
        ));

        $brief = [
            'recent_events'        => array_map(fn ($e) => $e->toArray(), $recentEvents),
            'events_by_source'     => $eventsBySource,
            'people'               => $people,
            'pending_commitments'  => array_map(fn ($c) => $c->toArray(), $pendingCommitments),
            'drifting_commitments' => [],
        ];

        $sessionStore->recordBriefAt(new \DateTimeImmutable());

        return new SsrResponse(
            content: json_encode($brief, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            statusCode: 200,
            headers: ['Content-Type' => 'application/json'],
        );
    }
}
