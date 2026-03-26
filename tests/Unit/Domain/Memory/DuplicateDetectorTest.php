<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Domain\Memory;

use Claudriel\Domain\Memory\DuplicateDetector;
use Claudriel\Entity\Person;
use PHPUnit\Framework\TestCase;

final class DuplicateDetectorTest extends TestCase
{
    public function test_detects_exact_email_duplicates(): void
    {
        $detector = new DuplicateDetector;
        $a = new Person(['uuid' => 'p1', 'name' => 'Jane Doe', 'email' => 'jane@example.com']);
        $b = new Person(['uuid' => 'p2', 'name' => 'J. Doe', 'email' => 'jane@example.com']);

        $candidates = $detector->detectPersonDuplicates([$a, $b], 0.8);

        self::assertCount(1, $candidates);
        self::assertSame(1.0, $candidates[0]['similarity_score']);
        self::assertContains('exact_email', $candidates[0]['match_reasons']);
    }

    public function test_detects_name_and_domain_levenshtein_duplicates(): void
    {
        $detector = new DuplicateDetector;
        $a = new Person(['uuid' => 'p1', 'name' => 'Jon Smith', 'email' => 'jon@acme.com']);
        $b = new Person(['uuid' => 'p2', 'name' => 'John Smith', 'email' => 'john@acme.com']);

        $candidates = $detector->detectPersonDuplicates([$a, $b], 0.8);

        self::assertCount(1, $candidates);
        self::assertContains('levenshtein_domain', $candidates[0]['match_reasons']);
    }

    public function test_threshold_filters_low_scores(): void
    {
        $detector = new DuplicateDetector;
        $a = new Person(['uuid' => 'p1', 'name' => 'Alice One', 'email' => 'alice@example.com']);
        $b = new Person(['uuid' => 'p2', 'name' => 'Alyce One', 'email' => 'alyce@example.com']);

        $candidates = $detector->detectPersonDuplicates([$a, $b], 0.95);

        self::assertSame([], $candidates);
    }
}
