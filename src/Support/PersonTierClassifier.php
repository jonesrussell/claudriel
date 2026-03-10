<?php

declare(strict_types=1);

namespace Claudriel\Support;

final class PersonTierClassifier
{
    private const AUTOMATED_PATTERNS = [
        'noreply@', 'no-reply@', 'notifications@', 'mailer-daemon@',
        'donotreply@', 'automated@', 'system@', 'alerts@',
    ];

    public static function classify(string $email, ?string $name = null): string
    {
        $lower = strtolower($email);

        foreach (self::AUTOMATED_PATTERNS as $pattern) {
            if (str_contains($lower, $pattern)) {
                return 'automated';
            }
        }

        return 'contact';
    }
}
