<?php

declare(strict_types=1);

namespace Claudriel\Temporal\Clock;

interface MonotonicClockInterface
{
    public function now(): int;
}
