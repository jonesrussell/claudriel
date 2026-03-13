<?php

declare(strict_types=1);

namespace Claudriel\Support;

final class SchedulePayloadNormalizer
{
    private const DEFAULT_TIMEZONE = 'America/Toronto';

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function normalize(array $payload, ?string $occurredAt = null): ?array
    {
        $title = $this->normalizeString($payload['title'] ?? $payload['subject'] ?? null);
        $source = $this->normalizeString($payload['source'] ?? null) ?? 'google-calendar';
        $calendarId = $this->normalizeString($payload['calendar_id'] ?? null);
        $externalId = $this->firstNonEmptyString([
            $payload['event_id'] ?? null,
            $payload['id'] ?? null,
            $payload['ical_uid'] ?? null,
            $payload['icaluid'] ?? null,
            $payload['iCalUID'] ?? null,
        ]);
        $recurringSeriesId = $this->firstNonEmptyString([
            $payload['recurring_event_id'] ?? null,
            $payload['recurringEventId'] ?? null,
            $payload['series_id'] ?? null,
            $payload['seriesId'] ?? null,
            $payload['parent_event_id'] ?? null,
        ]);

        $start = $this->parseDateTime(
            $payload['start_time'] ?? $payload['starts_at'] ?? $payload['start'] ?? null,
        );
        $end = $this->parseDateTime(
            $payload['end_time'] ?? $payload['ends_at'] ?? $payload['end'] ?? null,
        );

        $body = $this->normalizeString($payload['body'] ?? $payload['description'] ?? $payload['location'] ?? null) ?? '';

        if (($start === null || $end === null) && $body !== '') {
            [$parsedStart, $parsedEnd] = $this->parseTimeRangeFromBody($body, $occurredAt);
            $start ??= $parsedStart;
            $end ??= $parsedEnd;
        }

        if ($title === null || $start === null) {
            return null;
        }

        if ($end === null) {
            $end = $start->modify('+30 minutes');
        }

        return [
            'title' => $title,
            'source' => $source,
            'calendar_id' => $calendarId,
            'external_id' => $externalId,
            'recurring_series_id' => $recurringSeriesId,
            'start_time' => $start->format(\DateTimeInterface::ATOM),
            'end_time' => $end->format(\DateTimeInterface::ATOM),
            'organizer_name' => $this->normalizeString($payload['from_name'] ?? $payload['organizer_name'] ?? null),
            'organizer_email' => $this->normalizeString($payload['from_email'] ?? $payload['organizer_email'] ?? null),
            'notes' => $body,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function normalizeForLocalDate(array $payload, \DateTimeImmutable $localDate): ?array
    {
        $title = $this->normalizeString($payload['title'] ?? $payload['subject'] ?? null);
        $body = $this->normalizeString($payload['body'] ?? $payload['description'] ?? $payload['location'] ?? null) ?? '';

        if ($title === null || $body === '') {
            return null;
        }

        [$start, $end] = $this->parseTimeRangeForDate($body, $localDate->setTimezone($this->timezone())->setTime(0, 0));
        if ($start === null) {
            return null;
        }

        if ($end === null) {
            $end = $start->modify('+30 minutes');
        }

        return [
            'title' => $title,
            'source' => $this->normalizeString($payload['source'] ?? null) ?? 'google-calendar',
            'calendar_id' => $this->normalizeString($payload['calendar_id'] ?? null),
            'external_id' => $this->firstNonEmptyString([
                $payload['event_id'] ?? null,
                $payload['id'] ?? null,
                $payload['ical_uid'] ?? null,
                $payload['icaluid'] ?? null,
                $payload['iCalUID'] ?? null,
            ]),
            'recurring_series_id' => $this->firstNonEmptyString([
                $payload['recurring_event_id'] ?? null,
                $payload['recurringEventId'] ?? null,
                $payload['series_id'] ?? null,
                $payload['seriesId'] ?? null,
                $payload['parent_event_id'] ?? null,
            ]),
            'start_time' => $start->format(\DateTimeInterface::ATOM),
            'end_time' => $end->format(\DateTimeInterface::ATOM),
            'organizer_name' => $this->normalizeString($payload['from_name'] ?? $payload['organizer_name'] ?? null),
            'organizer_email' => $this->normalizeString($payload['from_email'] ?? $payload['organizer_email'] ?? null),
            'notes' => $body,
        ];
    }

    private function parseDateTime(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }

        if (is_array($value)) {
            $value = $value['dateTime'] ?? $value['date'] ?? null;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $trimmed = trim($value);
            if (preg_match('/(?:Z|[+\-]\d{2}:\d{2})$/', $trimmed) === 1) {
                return new \DateTimeImmutable($trimmed);
            }

            return new \DateTimeImmutable($trimmed, $this->timezone());
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable}
     */
    private function parseTimeRangeFromBody(string $body, ?string $occurredAt): array
    {
        if (! preg_match('/(\d{1,2})(?::(\d{2}))?\s*(am|pm)\s*-\s*(\d{1,2})(?::(\d{2}))?\s*(am|pm)/i', $body, $matches)) {
            return [null, null];
        }

        $baseDate = $this->inferLegacyScheduleDate($occurredAt);

        return $this->parseTimeRangeForDate($body, $baseDate);
    }

    /**
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable}
     */
    private function parseTimeRangeForDate(string $body, \DateTimeImmutable $baseDate): array
    {
        if (! preg_match('/(\d{1,2})(?::(\d{2}))?\s*(am|pm)\s*-\s*(\d{1,2})(?::(\d{2}))?\s*(am|pm)/i', $body, $matches)) {
            return [null, null];
        }

        $start = $this->applyClockTime($baseDate, $matches[1], $matches[2], $matches[3]);
        $end = $this->applyClockTime($baseDate, $matches[4], $matches[5], $matches[6]);

        if ($start !== null && $end !== null && $end <= $start) {
            $end = $end->modify('+1 day');
        }

        return [$start, $end];
    }

    private function inferLegacyScheduleDate(?string $occurredAt): \DateTimeImmutable
    {
        $occurred = $this->parseDateTime($occurredAt);
        if ($occurred === null) {
            return new \DateTimeImmutable('today', $this->timezone());
        }

        $occurred = $occurred->setTimezone($this->timezone());

        // Legacy sidecar schedule ingestion often happened the prior evening for the next day's plan.
        if ((int) $occurred->format('H') >= 18) {
            return $occurred->setTime(0, 0)->modify('+1 day');
        }

        return $occurred->setTime(0, 0);
    }

    private function applyClockTime(\DateTimeImmutable $date, string $hour, string $minute, string $meridiem): ?\DateTimeImmutable
    {
        $hours = (int) $hour;
        $minutes = (int) $minute;
        $meridiem = strtolower($meridiem);

        if ($hours < 1 || $hours > 12 || $minutes < 0 || $minutes > 59) {
            return null;
        }

        if ($meridiem === 'am') {
            $hours = $hours % 12;
        } else {
            $hours = ($hours % 12) + 12;
        }

        return $date->setTime($hours, $minutes);
    }

    private function timezone(): \DateTimeZone
    {
        $name = $_ENV['CLAUDRIEL_TIMEZONE']
            ?? getenv('CLAUDRIEL_TIMEZONE')
            ?: self::DEFAULT_TIMEZONE;

        try {
            return new \DateTimeZone((string) $name);
        } catch (\Throwable) {
            return new \DateTimeZone(self::DEFAULT_TIMEZONE);
        }
    }

    private function normalizeString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param  list<mixed>  $values
     */
    private function firstNonEmptyString(array $values): ?string
    {
        foreach ($values as $value) {
            $normalized = $this->normalizeString($value);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }
}
