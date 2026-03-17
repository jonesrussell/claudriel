<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Provider;

use Claudriel\Provider\ClaudrielServiceProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Route;
use Waaseyaa\Routing\WaaseyaaRouter;

final class ClaudrielServiceProviderRoutesTest extends TestCase
{
    public function test_routes_register_public_homepage_app_shell_and_admin_surface(): void
    {
        $provider = new ClaudrielServiceProvider;
        $provider->setKernelContext(dirname(__DIR__, 3), []);
        $provider->register();

        $router = new WaaseyaaRouter;
        $provider->routes($router);
        $routes = $router->getRouteCollection();

        $homepage = $routes->get('claudriel.homepage');
        $appShell = $routes->get('claudriel.app');
        $admin = $routes->get('claudriel.admin');
        $adminSession = $routes->get('claudriel.admin.session');

        self::assertInstanceOf(Route::class, $homepage);
        self::assertSame('/', $homepage->getPath());
        self::assertSame('Claudriel\\Controller\\PublicHomepageController::show', $homepage->getDefault('_controller'));

        self::assertInstanceOf(Route::class, $appShell);
        self::assertSame('/app', $appShell->getPath());
        self::assertSame('Claudriel\\Controller\\AppShellController::show', $appShell->getDefault('_controller'));

        self::assertInstanceOf(Route::class, $admin);
        self::assertSame('/admin', $admin->getPath());
        self::assertSame('Claudriel\\Controller\\AdminUiController::show', $admin->getDefault('_controller'));

        // Legacy session route still registered (closure-based, delegates to surface host)
        self::assertInstanceOf(Route::class, $adminSession);
        self::assertSame('/admin/session', $adminSession->getPath());

        // Canonical admin surface routes
        self::assertInstanceOf(Route::class, $routes->get('admin_surface.session'));
        self::assertSame('/admin/surface/session', $routes->get('admin_surface.session')->getPath());

        self::assertInstanceOf(Route::class, $routes->get('admin_surface.catalog'));
        self::assertSame('/admin/surface/catalog', $routes->get('admin_surface.catalog')->getPath());
    }
}
