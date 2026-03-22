<?php

declare(strict_types=1);

namespace Claudriel\Provider;

use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Telescope\Storage\SqliteTelescopeStore;
use Waaseyaa\Telescope\TelescopeServiceProvider as WaaseyaaTelescopeServiceProvider;

final class TelescopeServiceProvider extends ServiceProvider
{
    private ?WaaseyaaTelescopeServiceProvider $telescope = null;

    public function register(): void
    {
        // Telescope is configured lazily via getTelescope().
        // Query recording: waaseyaa/database-legacy has no query event hooks.
        // Event recording: entity events use Symfony EventDispatcher but providers
        // lack a registration point for generic listeners. Wire QueryRecorder and
        // EventRecorder here when those framework hooks land.
    }

    public function getTelescope(): WaaseyaaTelescopeServiceProvider
    {
        if ($this->telescope === null) {
            $storagePath = $this->getStoragePath();
            $store = $storagePath !== null
                ? SqliteTelescopeStore::createFromPath($storagePath)
                : SqliteTelescopeStore::createInMemory();

            $this->telescope = new WaaseyaaTelescopeServiceProvider(
                config: [
                    'enabled' => true,
                    'record' => [
                        'queries' => true,
                        'events' => true,
                        'requests' => true,
                        'cache' => true,
                        'slow_query_threshold' => 100.0,
                        'slow_queries_only' => false,
                    ],
                    'ignore_paths' => ['/health', '/api/broadcast/*', '/favicon.ico'],
                ],
                store: $store,
            );
        }

        return $this->telescope;
    }

    private function getStoragePath(): ?string
    {
        $varDir = dirname(__DIR__, 2).'/var';
        if (is_dir($varDir) || mkdir($varDir, 0o755, true)) {
            return $varDir.'/telescope.sqlite';
        }

        return null;
    }
}
