<?php

namespace Encore\Admin\Tests\Compatibility;

use Closure;
use Encore\Admin\Grid;
use Encore\Admin\Grid\Filter;
use Encore\Admin\Grid\Tools\QuickSearch;
use Illuminate\Container\Container;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class GridDeprecationTest extends TestCase
{
    private Container $previousContainer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        Container::setInstance(new Container());
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    private function withoutDeprecations(Closure $callback): mixed
    {
        $deprecations = [];
        set_error_handler(static function (int $severity, string $message) use (&$deprecations): bool {
            if ($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {
                $deprecations[] = $message;
                return true;
            }
            return false;
        });
        try {
            $result = $callback();
        } finally {
            restore_error_handler();
        }
        $this->assertSame([], $deprecations);
        return $result;
    }

    public function testFilterUrlsAcceptNullKeysAndKeepNumericAndNestedRemoval(): void
    {
        $request = Request::create('https://example.test/admin/orders', 'GET', [
            'status' => 'remove',
            'page' => 3,
            0 => 'remove',
            'nested' => ['remove' => 'yes', 'keep' => 'yes'],
            'sort' => 'created_at',
        ]);
        app()->instance('request', $request);
        $filter = (new ReflectionClass(Filter::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(Filter::class, 'fullUrlWithoutQuery');
        foreach ([[null, 'status', 'page', 0, 'nested.remove'], new Collection([null, 'status', 'page', 0, 'nested.remove'])] as $keys) {
            $url = $this->withoutDeprecations(fn () => $method->invoke($filter, $keys));
            parse_str(parse_url($url, PHP_URL_QUERY), $query);
            $this->assertSame(['nested' => ['keep' => 'yes'], 'sort' => 'created_at'], $query);
        }
    }

    public function testNullFilterKeyRetainsItsPreviousEmptyStringMeaning(): void
    {
        $request = Request::create('https://example.test/admin/orders', 'GET', ['' => 'remove', 'keep' => 'yes']);
        app()->instance('request', $request);
        $filter = (new ReflectionClass(Filter::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(Filter::class, 'fullUrlWithoutQuery');
        $url = $this->withoutDeprecations(fn () => $method->invoke($filter, [null]));
        $this->assertSame('https://example.test/admin/orders?keep=yes', $url);
    }

    private function assertSearchView(?Grid $grid, string $key): void
    {
        app()->instance('request', Request::create('https://example.test/admin/orders', 'GET', [$key => 'needle', 'status' => 'ready']));
        $view = $this->createStub(View::class);
        $factory = $this->createMock(Factory::class);
        $factory->expects($this->once())->method('make')->with('admin::grid.quick-search', [
            'action' => 'https://example.test/admin/orders?status=ready',
            'key' => $key,
            'value' => 'needle',
            'placeholder' => 'Find',
        ], [])->willReturn($view);
        app()->instance(Factory::class, $factory);
        $search = (new QuickSearch())->placeholder('Find');
        if ($grid !== null) {
            $search->setGrid($grid);
        }
        $this->assertSame($view, $this->withoutDeprecations(fn () => $search->render()));
    }

    public function testBoundGridQuickSearchUsesTheGridProperty(): void
    {
        $grid = (new ReflectionClass(Grid::class))->newInstanceWithoutConstructor();
        $this->assertSearchView($grid, '__search__');
    }

    public function testQuickSearchHonorsASubclassSearchKey(): void
    {
        $grid = (new ReflectionClass(CustomSearchKeyGrid::class))->newInstanceWithoutConstructor();
        $this->assertSearchView($grid, 'custom_search');
    }

    public function testStandaloneQuickSearchKeepsTheDefaultKey(): void
    {
        $this->assertSearchView(null, '__search__');
    }
}

class CustomSearchKeyGrid extends Grid
{
    public static $searchKey = 'custom_search';
}
