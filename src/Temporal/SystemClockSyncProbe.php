<?php

declare(strict_types=1);

namespace Claudriel\Temporal;

final class SystemClockSyncProbe implements ClockSyncProbeInterface
{
    public function read(): ClockSyncStatus
    {
        $timedatectl = @shell_exec('timedatectl show --property=NTPSynchronized --property=SystemClockSynchronized --property=Timezone --value 2>/dev/null');
        if (is_string($timedatectl) && trim($timedatectl) !== '') {
            $lines = preg_split('/\R/', trim($timedatectl)) ?: [];
            $ntpSynchronized = $this->normalizeBoolean($lines[0] ?? null);
            $systemClockSynchronized = $this->normalizeBoolean($lines[1] ?? null);

            return new ClockSyncStatus(
                provider: 'timedatectl',
                synchronized: $ntpSynchronized && $systemClockSynchronized,
                metadata: [
                    'ntp_synchronized' => $ntpSynchronized ? 'true' : 'false',
                    'system_clock_synchronized' => $systemClockSynchronized ? 'true' : 'false',
                    'timezone' => $lines[2] ?? null,
                ],
            );
        }

        $ntpstat = @shell_exec('ntpstat 2>/dev/null');
        if (is_string($ntpstat) && trim($ntpstat) !== '') {
            $normalized = mb_strtolower($ntpstat);

            return new ClockSyncStatus(
                provider: 'ntpstat',
                synchronized: str_contains($normalized, 'synchronised to ntp server'),
                metadata: ['raw' => trim($ntpstat)],
            );
        }

        return new ClockSyncStatus(
            provider: 'os',
            synchronized: false,
            metadata: ['fallback' => 'No sync provider available'],
        );
    }

    private function normalizeBoolean(mixed $value): bool
    {
        return in_array(mb_strtolower(trim((string) $value)), ['1', 'yes', 'true'], true);
    }
}
