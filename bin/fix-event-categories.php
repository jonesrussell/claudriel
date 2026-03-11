#!/usr/bin/env php
<?php

/**
 * One-time migration: re-categorize existing mc_event rows using EventCategorizer logic.
 *
 * Usage: php bin/fix-event-categories.php [path/to/waaseyaa.sqlite]
 */
$dbPath = $argv[1] ?? dirname(__DIR__).'/waaseyaa.sqlite';

if (! file_exists($dbPath)) {
    fwrite(STDERR, "Database not found: {$dbPath}\n");
    exit(1);
}

$pdo = new PDO("sqlite:{$dbPath}", null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

// Inline categorization logic matching EventCategorizer
$jobKeywords = ['application', 'interview', 'job', 'position', 'hiring', 'recruiter', 'resume', 'offer', 'salary', 'applied'];

function categorize(string $source, string $type, array $payload, array $jobKeywords): string
{
    if ($source === 'google-calendar') {
        $title = strtolower($payload['title'] ?? $payload['subject'] ?? '');
        foreach ($jobKeywords as $keyword) {
            if (str_contains($title, $keyword)) {
                return 'job_hunt';
            }
        }

        return 'schedule';
    }

    if ($source === 'gmail') {
        $subject = strtolower($payload['subject'] ?? '');
        $body = strtolower($payload['body'] ?? '');
        $combined = $subject.' '.$body;
        foreach ($jobKeywords as $keyword) {
            if (str_contains($combined, $keyword)) {
                return 'job_hunt';
            }
        }

        return 'people';
    }

    return 'notification';
}

$rows = $pdo->query('SELECT eid, _data FROM mc_event')->fetchAll(PDO::FETCH_ASSOC);
$updated = 0;

foreach ($rows as $row) {
    $data = json_decode($row['_data'], true) ?? [];
    $source = $data['source'] ?? '';
    $type = $data['type'] ?? '';
    $payloadJson = $data['payload'] ?? '{}';
    $payload = is_string($payloadJson) ? (json_decode($payloadJson, true) ?? []) : $payloadJson;

    $newCategory = categorize($source, $type, $payload, $jobKeywords);
    $oldCategory = $data['category'] ?? 'notification';

    if ($newCategory !== $oldCategory) {
        $data['category'] = $newCategory;
        $newData = json_encode($data, JSON_THROW_ON_ERROR);
        $stmt = $pdo->prepare('UPDATE mc_event SET _data = ? WHERE eid = ?');
        $stmt->execute([$newData, $row['eid']]);
        $updated++;

        $title = $payload['subject'] ?? $payload['title'] ?? 'no title';
        echo "  {$type}: {$oldCategory} -> {$newCategory} ({$title})\n";
    }
}

echo "Re-categorized {$updated} of ".count($rows)." events.\n";
