<?php

declare(strict_types=1);

namespace Claudriel\DayBrief;

use Waaseyaa\Cache\CacheItem;
use Waaseyaa\Cache\TagAwareCacheInterface;

final class CachedDayBriefAssembler
{
    private const int TTL = 3600;

    private const array TAGS = [
        'entity:mc_event',
        'entity:commitment',
        'entity:schedule_entry',
        'entity:person',
        'entity:triage_entry',
        'entity:skill',
        'entity:workspace',
    ];

    public function __construct(
        private readonly \Closure $inner,
        private readonly TagAwareCacheInterface $cache,
    ) {}

    /**
     * Cache-aside wrapper for DayBriefAssembler::assemble().
     *
     * The $snapshot parameter from the real assembler is intentionally omitted.
     * It defaults to now() and is per-request, so including it in the cache key
     * would bust every cache entry. Callers needing a custom snapshot should
     * call the inner assembler directly, bypassing the cache.
     */
    public function assemble(
        string $tenantId,
        \DateTimeImmutable $since,
        ?string $workspaceUuid = null,
    ): array {
        $cacheKey = sprintf('brief:%s:%s:%s', $tenantId, $workspaceUuid ?? 'all', $since->format('Y-m-d'));

        $item = $this->cache->get($cacheKey);
        if ($item instanceof CacheItem && $item->valid) {
            return $item->data;
        }

        $result = ($this->inner)($tenantId, $since, $workspaceUuid);
        $this->cache->set($cacheKey, $result, time() + self::TTL, self::TAGS);

        return $result;
    }
}
