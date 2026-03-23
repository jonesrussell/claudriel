<?php

declare(strict_types=1);

namespace Claudriel\Provider;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\AI\Vector\EmbeddingProviderFactory;
use Waaseyaa\AI\Vector\EntityEmbeddingCleanupListener;
use Waaseyaa\AI\Vector\EntityEmbeddingListener;
use Waaseyaa\AI\Vector\SqliteEmbeddingStorage;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class AiVectorServiceProvider extends ServiceProvider
{
    private ?SqliteEmbeddingStorage $storage = null;

    public function register(): void {}

    public function boot(): void
    {
        $dispatcher = $this->resolve(EventDispatcherInterface::class);
        if (! $dispatcher instanceof EventDispatcherInterface) {
            return;
        }

        $storage = $this->getStorage();
        $provider = EmbeddingProviderFactory::fromConfig([
            'ai' => [
                'embedding_provider' => $_ENV['EMBEDDING_PROVIDER'] ?? getenv('EMBEDDING_PROVIDER') ?: '',
                'openai_api_key' => $_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY') ?: '',
                'ollama_endpoint' => $_ENV['OLLAMA_ENDPOINT'] ?? getenv('OLLAMA_ENDPOINT') ?: '',
            ],
        ]);

        $listener = new EntityEmbeddingListener(
            storage: $storage,
            embeddingProvider: $provider,
        );
        $dispatcher->addListener(EntityEvents::POST_SAVE->value, [$listener, 'onPostSave']);

        $cleanup = new EntityEmbeddingCleanupListener($storage);
        $dispatcher->addListener(EntityEvents::POST_DELETE->value, [$cleanup, 'onPostDelete']);
    }

    private function getStorage(): SqliteEmbeddingStorage
    {
        if ($this->storage === null) {
            $storageDir = dirname(__DIR__, 2).'/storage';
            if (! is_dir($storageDir) && ! mkdir($storageDir, 0o755, true)) {
                error_log('AiVector: could not create storage/ directory');
            }

            $pdo = new \PDO('sqlite:'.$storageDir.'/embeddings.sqlite');
            $pdo->exec('PRAGMA journal_mode=WAL');
            $this->storage = new SqliteEmbeddingStorage($pdo);
        }

        return $this->storage;
    }
}
