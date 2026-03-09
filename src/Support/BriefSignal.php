<?php

declare(strict_types=1);

namespace Claudriel\Support;

final class BriefSignal
{
    public function __construct(private readonly string $filePath) {}

    public function touch(): void
    {
        $dir = dirname($this->filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($this->filePath, (string) time());
        clearstatcache(true, $this->filePath);
    }

    public function lastModified(): int
    {
        if (!file_exists($this->filePath)) {
            return 0;
        }
        clearstatcache(true, $this->filePath);
        return (int) filemtime($this->filePath);
    }

    public function hasChangedSince(int $sinceTimestamp): bool
    {
        $mtime = $this->lastModified();
        return $mtime > 0 && $mtime > $sinceTimestamp;
    }
}
