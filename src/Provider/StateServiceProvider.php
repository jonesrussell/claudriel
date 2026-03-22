<?php

declare(strict_types=1);

namespace Claudriel\Provider;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\State\SqlState;
use Waaseyaa\State\StateInterface;

final class StateServiceProvider extends ServiceProvider
{
    private ?StateInterface $state = null;

    public function register(): void
    {
        // DI registration when resolver supports set()
    }

    public function getState(): StateInterface
    {
        if ($this->state === null) {
            $database = $this->resolveDatabase();
            $this->state = new SqlState($database);
        }

        return $this->state;
    }

    private function resolveDatabase(): DatabaseInterface
    {
        // Use in-memory SQLite for standalone instantiation (tests, CLI).
        // In the app kernel, the shared DatabaseInterface should be injected instead.
        return DBALDatabase::createSqlite(':memory:');
    }
}
