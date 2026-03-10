<?php

declare(strict_types=1);

namespace Claudriel\Support;

final class ContentHasher
{
    public static function hash(array $payload): string
    {
        $source = $payload['source'] ?? '';

        return match ($source) {
            'google-calendar' => self::hashCalendar($payload),
            'gmail' => self::hashGmail($payload),
            default => self::hashGeneric($payload),
        };
    }

    private static function hashCalendar(array $payload): string
    {
        return hash('sha256', implode('|', [
            $payload['title'] ?? '',
            $payload['start_time'] ?? '',
            $payload['calendar_id'] ?? '',
        ]));
    }

    private static function hashGmail(array $payload): string
    {
        return hash('sha256', $payload['message_id'] ?? '');
    }

    private static function hashGeneric(array $payload): string
    {
        return hash('sha256', implode('|', [
            $payload['source'] ?? '',
            $payload['type'] ?? '',
            $payload['body'] ?? '',
        ]));
    }
}
