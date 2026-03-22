<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Provider;

use Claudriel\Provider\StateServiceProvider;
use PHPUnit\Framework\TestCase;
use Waaseyaa\State\StateInterface;

final class StateServiceProviderTest extends TestCase
{
    public function test_get_state_returns_state_interface(): void
    {
        $provider = new StateServiceProvider;
        $state = $provider->getState();

        self::assertInstanceOf(StateInterface::class, $state);
    }

    public function test_state_round_trip(): void
    {
        $provider = new StateServiceProvider;
        $state = $provider->getState();

        $state->set('test_key', 'test_value');
        self::assertSame('test_value', $state->get('test_key'));
    }

    public function test_state_returns_default_for_missing_key(): void
    {
        $provider = new StateServiceProvider;
        $state = $provider->getState();

        self::assertSame('fallback', $state->get('nonexistent', 'fallback'));
    }

    public function test_state_delete(): void
    {
        $provider = new StateServiceProvider;
        $state = $provider->getState();

        $state->set('to_delete', 'value');
        $state->delete('to_delete');
        self::assertNull($state->get('to_delete'));
    }

    public function test_state_multiple_operations(): void
    {
        $provider = new StateServiceProvider;
        $state = $provider->getState();

        $state->setMultiple(['a' => 1, 'b' => 2]);
        $result = $state->getMultiple(['a', 'b', 'c']);

        self::assertSame(1, $result['a']);
        self::assertSame(2, $result['b']);
        self::assertArrayNotHasKey('c', $result);
    }

    public function test_singleton_returns_same_instance(): void
    {
        $provider = new StateServiceProvider;
        self::assertSame($provider->getState(), $provider->getState());
    }
}
