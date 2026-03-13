<?php

declare(strict_types=1);

namespace Claudriel\Temporal;

final class ClockSyncStatus
{
    /**
     * @param  array<string, scalar|null>  $metadata
     */
    public function __construct(
        private readonly string $provider,
        private readonly bool $synchronized,
        private readonly array $metadata = [],
    ) {}

    public function provider(): string
    {
        return $this->provider;
    }

    public function synchronized(): bool
    {
        return $this->synchronized;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }
}
