<?php

declare(strict_types=1);

namespace Claudriel\Temporal\Clock;

interface WallClockInterface
{
    public function now(): \DateTimeImmutable;
}
