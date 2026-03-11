<?php

declare(strict_types=1);

namespace Claudriel\Ingestion;

final class EventCategorizer
{
    private const JOB_KEYWORDS = [
        'application', 'interview', 'job', 'position', 'hiring',
        'recruiter', 'resume', 'offer', 'salary', 'applied',
    ];

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function categorize(string $source, string $type, array $payload = []): string
    {
        if ($source === 'google-calendar') {
            return self::categorizeCalendar($payload);
        }

        if ($source === 'gmail') {
            return self::categorizeGmail($type, $payload);
        }

        return 'notification';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function categorizeCalendar(array $payload): string
    {
        $title = strtolower($payload['title'] ?? $payload['subject'] ?? '');
        foreach (self::JOB_KEYWORDS as $keyword) {
            if (str_contains($title, $keyword)) {
                return 'job_hunt';
            }
        }

        return 'schedule';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function categorizeGmail(string $type, array $payload): string
    {
        $subject = strtolower($payload['subject'] ?? '');
        $body = strtolower($payload['body'] ?? '');
        $combined = $subject.' '.$body;

        foreach (self::JOB_KEYWORDS as $keyword) {
            if (str_contains($combined, $keyword)) {
                return 'job_hunt';
            }
        }

        return 'people';
    }
}
