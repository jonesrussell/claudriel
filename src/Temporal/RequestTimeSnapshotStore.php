<?php

declare(strict_types=1);

namespace Claudriel\Temporal;

final class RequestTimeSnapshotStore
{
    /** @var array<string, TimeSnapshot> */
    private array $snapshots = [];

    public function remember(string $scopeKey, callable $resolver): TimeSnapshot
    {
        if (! isset($this->snapshots[$scopeKey])) {
            $snapshot = $resolver();
            if (! $snapshot instanceof TimeSnapshot) {
                throw new \RuntimeException('Temporal snapshot resolver must return a TimeSnapshot.');
            }

            $this->snapshots[$scopeKey] = $snapshot;
        }

        return $this->snapshots[$scopeKey];
    }

    public function forget(string $scopeKey): void
    {
        unset($this->snapshots[$scopeKey]);
    }

    public function reset(): void
    {
        $this->snapshots = [];
    }
}
