<?php

declare(strict_types=1);

namespace Claudriel\Temporal\Clock;

final class SystemMonotonicClock implements MonotonicClockInterface
{
    public function now(): int
    {
        return hrtime(true);
    }
}
