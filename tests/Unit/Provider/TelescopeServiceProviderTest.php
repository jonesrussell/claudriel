<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Provider;

use Claudriel\Provider\TelescopeServiceProvider;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Telescope\Recorder\CacheRecorder;
use Waaseyaa\Telescope\Recorder\EventRecorder;
use Waaseyaa\Telescope\Recorder\QueryRecorder;
use Waaseyaa\Telescope\Recorder\RequestRecorder;

final class TelescopeServiceProviderTest extends TestCase
{
    public function test_provides_all_recorders_when_enabled(): void
    {
        $provider = new TelescopeServiceProvider;
        $telescope = $provider->getTelescope();

        self::assertTrue($telescope->isEnabled());
        self::assertInstanceOf(QueryRecorder::class, $telescope->getQueryRecorder());
        self::assertInstanceOf(EventRecorder::class, $telescope->getEventRecorder());
        self::assertInstanceOf(RequestRecorder::class, $telescope->getRequestRecorder());
        self::assertInstanceOf(CacheRecorder::class, $telescope->getCacheRecorder());
    }

    public function test_store_persists_and_retrieves_entries(): void
    {
        $provider = new TelescopeServiceProvider;
        $telescope = $provider->getTelescope();
        $store = $telescope->getStore();

        $store->store('test', ['message' => 'hello']);

        $entries = $store->query('test');
        self::assertCount(1, $entries);
        self::assertSame('hello', $entries[0]->data['message']);
    }

    public function test_ignores_health_and_broadcast_paths(): void
    {
        $provider = new TelescopeServiceProvider;
        $telescope = $provider->getTelescope();
        $recorder = $telescope->getRequestRecorder();

        $recorder->record('GET', '/health', 200, 1.0);
        $entries = $telescope->getStore()->query('request');
        self::assertCount(0, $entries);

        $recorder->record('GET', '/brief', 200, 5.0);
        $entries = $telescope->getStore()->query('request');
        self::assertCount(1, $entries);
    }
}
