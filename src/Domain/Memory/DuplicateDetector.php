<?php

declare(strict_types=1);

namespace Claudriel\Domain\Memory;

use Waaseyaa\Entity\ContentEntityInterface;

final class DuplicateDetector
{
    /**
     * @param  list<ContentEntityInterface>  $persons
     * @return list<array{source_uuid: string, target_uuid: string, similarity_score: float, match_reasons: list<string>}>
     */
    public function detectPersonDuplicates(array $persons, float $threshold = 0.8): array
    {
        $candidates = [];
        $count = count($persons);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $left = $persons[$i];
                $right = $persons[$j];

                $leftUuid = (string) ($left->get('uuid') ?? '');
                $rightUuid = (string) ($right->get('uuid') ?? '');
                if ($leftUuid === '' || $rightUuid === '' || $leftUuid === $rightUuid) {
                    continue;
                }

                [$score, $reasons] = $this->scorePair($left, $right);
                if ($score < $threshold || $reasons === []) {
                    continue;
                }

                $candidates[] = [
                    'source_uuid' => $leftUuid,
                    'target_uuid' => $rightUuid,
                    'similarity_score' => $score,
                    'match_reasons' => $reasons,
                ];
            }
        }

        return $candidates;
    }

    /**
     * @return array{0: float, 1: list<string>}
     */
    private function scorePair(ContentEntityInterface $left, ContentEntityInterface $right): array
    {
        $reasons = [];
        $score = 0.0;

        $leftEmail = $this->normalizeEmail((string) ($left->get('email') ?? ''));
        $rightEmail = $this->normalizeEmail((string) ($right->get('email') ?? ''));
        if ($leftEmail !== '' && $leftEmail === $rightEmail) {
            $score = max($score, 1.0);
            $reasons[] = 'exact_email';
        }

        $leftName = $this->normalizeName((string) ($left->get('name') ?? ''));
        $rightName = $this->normalizeName((string) ($right->get('name') ?? ''));
        if ($leftName !== '' && $leftName === $rightName) {
            $score = max($score, 0.9);
            $reasons[] = 'normalized_name';
        }

        $leftDomain = $this->emailDomain($leftEmail);
        $rightDomain = $this->emailDomain($rightEmail);
        if (
            $leftName !== ''
            && $rightName !== ''
            && $leftDomain !== ''
            && $leftDomain === $rightDomain
            && levenshtein($leftName, $rightName) <= 2
        ) {
            $score = max($score, 0.8);
            $reasons[] = 'levenshtein_domain';
        }

        $leftPhone = $this->normalizePhone((string) ($left->get('phone') ?? ''));
        $rightPhone = $this->normalizePhone((string) ($right->get('phone') ?? ''));
        if ($leftPhone !== '' && $leftPhone === $rightPhone) {
            $score = max($score, 0.95);
            $reasons[] = 'phone_match';
        }

        return [$score, array_values(array_unique($reasons))];
    }

    private function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private function emailDomain(string $email): string
    {
        $parts = explode('@', $email);

        return count($parts) === 2 ? $parts[1] : '';
    }

    private function normalizeName(string $name): string
    {
        $normalized = mb_strtolower(trim($name));
        $normalized = preg_replace('/[^a-z0-9]+/i', ' ', $normalized);

        return trim((string) $normalized);
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }
}
