<?php

declare(strict_types=1);

namespace Claudriel\Temporal;

final class TimeSnapshot
{
    public function __construct(
        private readonly \DateTimeImmutable $capturedAtUtc,
        private readonly \DateTimeImmutable $capturedAtLocal,
        private readonly int $monotonicNanoseconds,
        private readonly string $timezone,
    ) {}

    public function utc(): \DateTimeImmutable
    {
        return $this->capturedAtUtc;
    }

    public function local(): \DateTimeImmutable
    {
        return $this->capturedAtLocal;
    }

    public function monotonicNanoseconds(): int
    {
        return $this->monotonicNanoseconds;
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    /**
     * @return array{
     *   utc: string,
     *   local: string,
     *   timezone: string,
     *   monotonic_ns: int
     * }
     */
    public function toArray(): array
    {
        return [
            'utc' => $this->capturedAtUtc->format(\DateTimeInterface::ATOM),
            'local' => $this->capturedAtLocal->format(\DateTimeInterface::ATOM),
            'timezone' => $this->timezone,
            'monotonic_ns' => $this->monotonicNanoseconds,
        ];
    }
}
