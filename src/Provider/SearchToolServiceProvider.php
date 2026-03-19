<?php

declare(strict_types=1);

namespace Claudriel\Provider;

use Claudriel\Controller\InternalBriefController;
use Claudriel\Controller\InternalEventController;
use Claudriel\Controller\InternalSearchController;
use Claudriel\Domain\Chat\InternalApiTokenGenerator;
use Claudriel\Domain\DayBrief\Assembler\DayBriefAssembler;
use Claudriel\Support\DriftDetector;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;

final class SearchToolServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->singleton(InternalBriefController::class, function () {
            $entityTypeManager = $this->resolve(EntityTypeManager::class);
            $eventRepo = $entityTypeManager->getRepository('mc_event');
            $commitmentRepo = $entityTypeManager->getRepository('commitment');
            $personRepo = $entityTypeManager->getRepository('person');

            $assembler = new DayBriefAssembler(
                $eventRepo,
                $commitmentRepo,
                new DriftDetector($commitmentRepo),
                $personRepo,
            );

            return new InternalBriefController(
                $assembler,
                $this->resolve(InternalApiTokenGenerator::class),
                $this->resolve('tenant_id') ?? 'default',
            );
        });

        $this->singleton(InternalEventController::class, function () {
            return new InternalEventController(
                $this->resolve(EntityTypeManager::class)->getRepository('mc_event'),
                $this->resolve(InternalApiTokenGenerator::class),
                $this->resolve('tenant_id') ?? 'default',
            );
        });

        $this->singleton(InternalSearchController::class, function () {
            $entityTypeManager = $this->resolve(EntityTypeManager::class);

            return new InternalSearchController(
                $entityTypeManager->getRepository('person'),
                $entityTypeManager->getRepository('commitment'),
                $entityTypeManager->getRepository('mc_event'),
                $this->resolve(InternalApiTokenGenerator::class),
                $this->resolve('tenant_id') ?? 'default',
            );
        });
    }

    public function routes(WaaseyaaRouter $router, ?EntityTypeManager $entityTypeManager = null): void
    {
        $briefRoute = RouteBuilder::create('/api/internal/brief/generate')
            ->controller(InternalBriefController::class.'::generate')
            ->allowAll()
            ->methods('POST')
            ->build();
        $briefRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.internal.brief.generate', $briefRoute);

        $eventSearchRoute = RouteBuilder::create('/api/internal/events/search')
            ->controller(InternalEventController::class.'::search')
            ->allowAll()
            ->methods('GET')
            ->build();
        $eventSearchRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.internal.events.search', $eventSearchRoute);

        $globalSearchRoute = RouteBuilder::create('/api/internal/search/global')
            ->controller(InternalSearchController::class.'::searchGlobal')
            ->allowAll()
            ->methods('GET')
            ->build();
        $globalSearchRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.internal.search.global', $globalSearchRoute);
    }
}
