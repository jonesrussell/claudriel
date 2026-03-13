<?php

declare(strict_types=1);

namespace Claudriel\Temporal;

interface ClockSyncProbeInterface
{
    public function read(): ClockSyncStatus;
}
