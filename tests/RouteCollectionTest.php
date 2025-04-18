<?php

declare(strict_types=1);

namespace Yiisoft\Router\Tests;

use InvalidArgumentException;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Yiisoft\Middleware\Dispatcher\MiddlewareDispatcher;
use Yiisoft\Middleware\Dispatcher\MiddlewareFactory;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;
use Yiisoft\Router\RouteCollection;
use Yiisoft\Router\RouteCollector;
use Yiisoft\Router\RouteNotFoundException;
use Yiisoft\Router\Tests\Support\TestController;
use Yiisoft\Router\Tests\Support\TestMiddleware1;
use Yiisoft\Router\Tests\Support\TestMiddleware2;
use Yiisoft\Router\Tests\Support\TestMiddleware3;
use Yiisoft\Test\Support\Container\SimpleContainer;

final class RouteCollectionTest extends TestCase
{
    public function testAddRouteWithDuplicateName(): void
    {
        $listRoute = Route::get('/')->name('my-route');
        $viewRoute = Route::get('/{id}')->name('my-route');

        $group = Group::create()->routes($listRoute, $viewRoute);

        $collector = new RouteCollector();
        $collector->addRoute($group);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("A route with name 'my-route' already exists.");
        $routeCollection = new RouteCollection($collector);
        $routeCollection->getRoutes();
    }

    public function testAddRouteWithDuplicateName2(): void
    {
        $listRoute = Route::get('/')->name('my-route');
        $viewRoute = Route::get('/{id}')->name('my-route');

        $group = Group::create()->routes($listRoute);

        $collector = new RouteCollector();
        $collector->addRoute($group, $viewRoute);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("A route with name 'my-route' already exists.");
        $routeCollection = new RouteCollection($collector);
        $routeCollection->getRoutes();
    }

    public function testRouteNotFound(): void
    {
        $listRoute = Route::get('/')->name('my-route');

        $group = Group::create()->routes($listRoute);

        $collector = new RouteCollector();
        $collector->addRoute($group);

        $this->expectException(RouteNotFoundException::class);
        $this->expectExceptionMessage('Cannot generate URI for route "wrong-name"; route not found');
        $routeCollection = new RouteCollection($collector);
        $routeCollection->getRoute('wrong-name');
    }

    public function testRouteOverride(): void
    {
        $listRoute = Route::get('/')->name('my-route');
        $viewRoute = Route::get('/{id}')
            ->name('my-route')
            ->override();

        $group = Group::create()->routes($listRoute, $viewRoute);

        $collector = new RouteCollector();
        $collector->addRoute($group);

        $routeCollection = new RouteCollection($collector);
        $route = $routeCollection->getRoute('my-route');
        $this->assertSame('/{id}', $route->getData('pattern'));
    }

    public function testRouteWithoutAction(): void
    {
        $group = Group::create()
            ->middleware(fn () => 1)
            ->routes(
                Route::get('/test')
                    ->action(fn () => 2)
                    ->name('test'),
                Route::get('/images/{sile}')->name('image')
            );

        $collector = new RouteCollector();
        $collector->addRoute($group);

        $routeCollection = new RouteCollection($collector);
        $route = $routeCollection->getRoute('image');
        $this->assertFalse($route->getData('hasMiddlewares'));
    }

    public function testGetRouterTree(): void
    {
        $group1 = Group::create('/api')
            ->routes(
                Route::get('/test')
                    ->action(fn () => 2)
                    ->name('/test'),
                Route::get('/images/{sile}')->name('/image'),
                Group::create('/v1')
                    ->routes(
                        Route::get('/posts')->name('/posts'),
                        Route::get('/post/{sile}')->name('/post/view')
                    )
                    ->namePrefix('/v1'),
                Group::create('/v1')
                    ->routes(
                        Route::get('/tags')->name('/tags'),
                        Route::get('/tag/{slug}')->name('/tag/view'),
                    )
                    ->namePrefix('/v1'),
            )->namePrefix('/api');

        $group2 = Group::create('/api')
            ->routes(
                Route::get('/posts')->name('/posts'),
                Route::get('/post/{sile}')->name('/post/view'),
            )
            ->namePrefix('/api');

        $collector = new RouteCollector();
        $collector->addRoute($group1, $group2);

        $routeCollection = new RouteCollection($collector);
        $routeTree = $routeCollection->getRouteTree();

        $this->assertSame(
            [
                '[/api/test] GET /api/test',
                '[/api/image] GET /api/images/{sile}',
                '/v1' => [
                    '[/api/v1/posts] GET /api/v1/posts',
                    '[/api/v1/post/view] GET /api/v1/post/{sile}',
                    '[/api/v1/tags] GET /api/v1/tags',
                    '[/api/v1/tag/view] GET /api/v1/tag/{slug}',
                ],
                '[/api/posts] GET /api/posts',
                '[/api/post/view] GET /api/post/{sile}',
            ],
            $routeTree
        );
    }

    public function testGetRoutes(): void
    {
        $group = Group::create()
            ->middleware(fn () => 1)
            ->routes(
                Route::get('/test')
                    ->action(fn () => 2)
                    ->name('test'),
                Route::get('/images/{sile}')->name('image')
            );

        $collector = new RouteCollector();
        $collector->addRoute($group);

        $routeCollection = new RouteCollection($collector);
        $routes = $routeCollection->getRoutes();
        $this->assertArrayHasKey('test', $routes);
        $this->assertArrayHasKey('image', $routes);
    }

    public function testGroupHost(): void
    {
        $group = Group::create()
            ->routes(
                Group::create()
                    ->routes(
                        Route::get('/project/{name}')->name('project')
                    )
                    ->hosts('https://yiipowered.com/', 'https://yiiframework.ru/'),
                Group::create()
                    ->routes(
                        Route::get('/user/{username}')->name('user')
                    ),
                Route::get('/images/{name}')->name('image')
            )
            ->host('https://yiiframework.com/');

        $collector = new RouteCollector();
        $collector->addRoute($group);

        $routeCollection = new RouteCollection($collector);
        $route1 = $routeCollection->getRoute('image');
        $route2 = $routeCollection->getRoute('project');
        $route3 = $routeCollection->getRoute('user');
        $this->assertSame('https://yiiframework.com', $route1->getData('host'));
        $this->assertCount(2, $route2->getData('hosts'));
        $this->assertSame(['https://yiipowered.com', 'https://yiiframework.ru'], $route2->getData('hosts'));
        $this->assertSame('https://yiiframework.com', $route3->getData('host'));
    }

    public function testGroupName(): void
    {
        $group = Group::create('api')
            ->routes(
                Group::create()->routes(
                    Group::create('/v1')
                        ->routes(
                            Route::get('/package/downloads/{package}')->name('/package/downloads')
                        )
                        ->namePrefix('/v1'),
                    Group::create()->routes(
                        Route::get('')->name('/index')
                    ),
                    Route::get('/post/{slug}')->name('/post/view'),
                    Route::get('/user/{username}'),
                )
            )->namePrefix('api');

        $collector = new RouteCollector();
        $collector->addRoute($group);

        $routeCollection = new RouteCollection($collector);
        $route1 = $routeCollection->getRoute('api/post/view');
        $route2 = $routeCollection->getRoute('api/v1/package/downloads');
        $route3 = $routeCollection->getRoute('api/index');
        $route4 = $routeCollection->getRoute('GET api/user/{username}');
        $this->assertInstanceOf(Route::class, $route1);
        $this->assertInstanceOf(Route::class, $route2);
        $this->assertInstanceOf(Route::class, $route3);
        $this->assertInstanceOf(Route::class, $route4);
    }

    public function testCollectorMiddlewareFullstackCalled(): void
    {
        $action = fn (ServerRequestInterface $request) => new Response(
            200,
            [],
            null,
            '1.1',
            implode('', $request->getAttributes())
        );
        $listRoute = Route::get('/')
            ->action($action)
            ->name('list');
        $viewRoute = Route::get('/{id}')
            ->action($action)
            ->name('view');

        $group = Group::create(null)->routes($listRoute);

        $middleware = function (ServerRequestInterface $request, RequestHandlerInterface $handler) {
            $request = $request->withAttribute('middleware', 'middleware1');
            return $handler->handle($request);
        };

        $collector = new RouteCollector();
        $collector->middleware($middleware);
        $collector->addRoute($group, $viewRoute);

        $routeCollection = new RouteCollection($collector);
        $route1 = $routeCollection->getRoute('list');
        $route2 = $routeCollection->getRoute('view');
        $request = new ServerRequest('GET', '/');
        $response1 = $this->getDispatcher()
            ->withMiddlewares($route1->getData('enabledMiddlewares'))
            ->dispatch($request, $this->getRequestHandler());
        $response2 = $this->getDispatcher()
            ->withMiddlewares($route2->getData('enabledMiddlewares'))
            ->dispatch($request, $this->getRequestHandler());

        $this->assertEquals('middleware1', $response1->getReasonPhrase());
        $this->assertEquals('middleware1', $response2->getReasonPhrase());
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function testMiddlewaresOrder(bool $groupWrapped): void
    {
        $request = new ServerRequest('GET', '/');

        $collector = new RouteCollector();

        $collector
            ->middleware(TestMiddleware2::class)
            ->prependMiddleware(TestMiddleware1::class);

        $rawRoute = Route::get('/')
            ->middleware(TestMiddleware3::class)
            ->action([TestController::class, 'index'])
            ->name('main');

        $collector->addRoute(
            $groupWrapped ? Group::create()->routes($rawRoute) : $rawRoute
        );

        $route = (new RouteCollection($collector))->getRoute('main');

        $dispatcher = $this
            ->getDispatcher(
                new SimpleContainer([
                    TestMiddleware1::class => new TestMiddleware1(),
                    TestMiddleware2::class => new TestMiddleware2(),
                    TestMiddleware3::class => new TestMiddleware3(),
                    TestController::class => new TestController(),
                ])
            )
            ->withMiddlewares($route->getData('enabledMiddlewares'));

        $response = $dispatcher->dispatch($request, $this->getRequestHandler());
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('123', (string) $response->getBody());
    }

    public function testStaticRouteWithCollectorMiddlewares(): void
    {
        $request = new ServerRequest('GET', '/');

        $collector = new RouteCollector();
        $collector->middleware(TestMiddleware1::class);

        $collector->addRoute(
            Route::get('i/{image}')->name('image')
        );

        $route = (new RouteCollection($collector))->getRoute('image');

        $dispatcher = $this
            ->getDispatcher(
                new SimpleContainer([
                    TestMiddleware1::class => new TestMiddleware1(),
                ])
            )
            ->withMiddlewares($route->getData('enabledMiddlewares'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stack is empty.');
        $dispatcher->dispatch($request, $this->getRequestHandler());
    }

    private function getRequestHandler(): RequestHandlerInterface
    {
        return new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): Response
            {
                return new Response(404);
            }
        };
    }

    private function getDispatcher(?ContainerInterface $container = null): MiddlewareDispatcher
    {
        $container ??= $this->createMock(ContainerInterface::class);
        return new MiddlewareDispatcher(
            new MiddlewareFactory($container),
            $this->createMock(EventDispatcherInterface::class)
        );
    }
}
