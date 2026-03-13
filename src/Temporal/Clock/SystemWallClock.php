<?php

declare(strict_types=1);

namespace Claudriel\Temporal\Clock;

final class SystemWallClock implements WallClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable;
    }
}
