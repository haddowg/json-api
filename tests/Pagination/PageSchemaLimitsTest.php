<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Pagination;

use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Pagination\FixedPagePaginator;
use haddowg\JsonApi\Pagination\MultiPaginator;
use haddowg\JsonApi\Pagination\OffsetPaginator;
use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Pagination\PaginatorInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Server\Server;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Each built-in paginator projects the default it applies to an absent key and, for a
 * client-sized member, the cap it clamps to, so the `page` schema states what the
 * paginator serves.
 */
#[CoversClass(PagePaginator::class)]
#[CoversClass(OffsetPaginator::class)]
#[CoversClass(CursorPaginator::class)]
#[CoversClass(FixedPagePaginator::class)]
#[CoversClass(MultiPaginator::class)]
final class PageSchemaLimitsTest extends TestCase
{
    #[Test]
    public function aPagePaginatorProjectsItsDefaultsAndCap(): void
    {
        $schema = PagePaginator::make()->withDefaultPage(2)->withDefaultPerPage(25)->withMaxPerPage(100)->describePageSchema();

        self::assertSame(['type' => 'integer', 'description' => 'The page number to retrieve.', 'default' => 2, 'minimum' => 1], $this->member($schema, 'number'));
        self::assertSame(['type' => 'integer', 'description' => 'The number of resources per page.', 'default' => 25, 'minimum' => 1, 'maximum' => 100], $this->member($schema, 'size'));
    }

    #[Test]
    public function anOffsetPaginatorProjectsItsDefaultsAndCap(): void
    {
        $schema = OffsetPaginator::make()->withDefaultOffset(5)->withDefaultLimit(20)->withMaxPerPage(50)->describePageSchema();

        self::assertSame(5, $this->member($schema, 'offset')['default'] ?? null);
        self::assertSame(0, $this->member($schema, 'offset')['minimum'] ?? null);
        self::assertArrayNotHasKey('maximum', $this->member($schema, 'offset'));
        self::assertSame(20, $this->member($schema, 'limit')['default'] ?? null);
        self::assertSame(1, $this->member($schema, 'limit')['minimum'] ?? null);
        self::assertSame(50, $this->member($schema, 'limit')['maximum'] ?? null);
    }

    #[Test]
    public function aCursorPaginatorProjectsItsDefaultSizeAndCap(): void
    {
        $schema = CursorPaginator::make()->withDefaultSize(30)->withMaxPerPage(60)->describePageSchema();

        self::assertSame(30, $this->member($schema, 'size')['default'] ?? null);
        self::assertSame(1, $this->member($schema, 'size')['minimum'] ?? null);
        self::assertSame(60, $this->member($schema, 'size')['maximum'] ?? null);
        // The cursor tokens have no default.
        self::assertArrayNotHasKey('default', $this->member($schema, 'after'));
        self::assertArrayNotHasKey('default', $this->member($schema, 'before'));
    }

    #[Test]
    public function aFixedPagePaginatorProjectsItsDefaultPageAndStatesItsSize(): void
    {
        $number = $this->member(FixedPagePaginator::make(20)->withDefaultPage(3)->describePageSchema(), 'number');

        self::assertSame(3, $number['default'] ?? null);
        self::assertSame(1, $number['minimum'] ?? null);
        self::assertArrayNotHasKey('maximum', $number);
        self::assertSame('The page number to retrieve. Each page holds 20 resources.', $number['description'] ?? null);

        self::assertSame(
            'The page number to retrieve. Each page holds 1 resource.',
            $this->member(FixedPagePaginator::make(1)->describePageSchema(), 'number')['description'] ?? null,
        );
    }

    #[Test]
    public function renamedKeysCarryTheDefaultsAndCap(): void
    {
        $page = PagePaginator::make()->withPageKey('p')->withPerPageKey('per')->withDefaultPerPage(25)->describePageSchema();
        self::assertSame(1, $this->member($page, 'p')['default'] ?? null);
        self::assertSame(25, $this->member($page, 'per')['default'] ?? null);
        self::assertSame(100, $this->member($page, 'per')['maximum'] ?? null);

        $offset = OffsetPaginator::make()->withOffsetKey('skip')->withLimitKey('take')->withDefaultLimit(10)->describePageSchema();
        self::assertSame(0, $this->member($offset, 'skip')['default'] ?? null);
        self::assertSame(10, $this->member($offset, 'take')['default'] ?? null);
        self::assertSame(100, $this->member($offset, 'take')['maximum'] ?? null);

        $cursor = CursorPaginator::make()->withSizeKey('limit')->describePageSchema();
        self::assertSame(15, $this->member($cursor, 'limit')['default'] ?? null);
        self::assertSame(100, $this->member($cursor, 'limit')['maximum'] ?? null);

        $fixed = FixedPagePaginator::make()->withPageKey('p')->withDefaultPage(2)->describePageSchema();
        self::assertSame(2, $this->member($fixed, 'p')['default'] ?? null);
    }

    #[Test]
    public function aDisabledCapEmitsNoMaximum(): void
    {
        $page = PagePaginator::make()->withDefaultPerPage(500)->withMaxPerPage(0)->describePageSchema();
        self::assertSame(500, $this->member($page, 'size')['default'] ?? null);
        self::assertArrayNotHasKey('maximum', $this->member($page, 'size'));

        $offset = OffsetPaginator::make()->withMaxPerPage(0)->describePageSchema();
        self::assertArrayNotHasKey('maximum', $this->member($offset, 'limit'));

        $cursor = CursorPaginator::make()->withMaxPerPage(0)->describePageSchema();
        self::assertArrayNotHasKey('maximum', $this->member($cursor, 'size'));
    }

    #[Test]
    public function aDefaultAboveTheCapProjectsTheClampedSizeTheServerServes(): void
    {
        $paginator = PagePaginator::make()->withDefaultPerPage(200)->withMaxPerPage(50);

        self::assertSame(50, $this->member($paginator->describePageSchema(), 'size')['default'] ?? null);
    }

    #[Test]
    public function eachMultiPaginatorArmCarriesItsOwnNumbers(): void
    {
        $menu = new MultiPaginator(
            PagePaginator::make()->withDefaultPerPage(25)->withMaxPerPage(100),
            OffsetPaginator::make()->withDefaultLimit(40)->withMaxPerPage(200),
            CursorPaginator::make()->withDefaultSize(10)->withMaxPerPage(0),
        );

        $branches = $menu->describePageSchema()->toArray()['oneOf'] ?? null;
        self::assertIsArray($branches);
        self::assertCount(3, $branches);
        [$page, $offset, $cursor] = \array_map(static fn(mixed $branch): array => \is_array($branch) ? $branch : [], $branches);

        self::assertSame(25, $page['properties']['size']['default'] ?? null);
        self::assertSame(100, $page['properties']['size']['maximum'] ?? null);
        self::assertSame(40, $offset['properties']['limit']['default'] ?? null);
        self::assertSame(200, $offset['properties']['limit']['maximum'] ?? null);
        self::assertSame(10, $cursor['properties']['size']['default'] ?? null);
        self::assertIsArray($cursor['properties']['size'] ?? null);
        self::assertArrayNotHasKey('maximum', $cursor['properties']['size']);
    }

    #[Test]
    public function aResourceOverrideProjectsItsOwnNumbersAndAnInheritingResourceTheServerDefaults(): void
    {
        $server = Server::make()->withDefaultPaginator(PagePaginator::make()->withDefaultPerPage(20)->withMaxPerPage(80));

        $inheriting = (new LimitsInheritingResource())->pagination($server->defaultPaginator());
        self::assertNotNull($inheriting);
        $size = $this->member($inheriting->describePageSchema(), 'size');
        self::assertSame(20, $size['default'] ?? null);
        self::assertSame(80, $size['maximum'] ?? null);

        $perPage = (new LimitsPerPageResource())->pagination($server->defaultPaginator());
        self::assertNotNull($perPage);
        $size = $this->member($perPage->describePageSchema(), 'size');
        self::assertSame(25, $size['default'] ?? null);
        self::assertSame(100, $size['maximum'] ?? null);

        $overriding = (new LimitsOverridingResource())->pagination($server->defaultPaginator());
        $limit = $this->member($overriding->describePageSchema(), 'limit');
        self::assertSame(50, $limit['default'] ?? null);
        self::assertSame(250, $limit['maximum'] ?? null);
    }

    #[Test]
    public function aRelationPaginatorProjectsItsOwnNumbersOverTheFallback(): void
    {
        $fallback = PagePaginator::make()->withDefaultPerPage(20);
        $relation = HasMany::make('comments', 'comments')->paginate(CursorPaginator::make()->withDefaultSize(5)->withMaxPerPage(25))->build();
        self::assertInstanceOf(RelationInterface::class, $relation);

        $paginator = $relation->pagination($fallback);
        self::assertInstanceOf(PaginatorInterface::class, $paginator);
        $size = $this->member($paginator->describePageSchema(), 'size');
        self::assertSame(5, $size['default'] ?? null);
        self::assertSame(25, $size['maximum'] ?? null);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function member(Schema $schema, string $key): array
    {
        $properties = $schema->toArray()['properties'] ?? null;
        self::assertIsArray($properties);
        $member = $properties[$key] ?? null;
        self::assertIsArray($member);

        return $member;
    }
}

final class LimitsInheritingResource extends AbstractResource
{
    public static string $type = 'inheriting';

    public function fields(): array
    {
        return [Id::make()];
    }
}

final class LimitsPerPageResource extends AbstractResource
{
    public static string $type = 'per-page';

    protected ?int $perPage = 25;

    public function fields(): array
    {
        return [Id::make()];
    }
}

final class LimitsOverridingResource extends AbstractResource
{
    public static string $type = 'overriding';

    public function fields(): array
    {
        return [Id::make()];
    }

    public function pagination(?PaginatorInterface $serverDefault): PaginatorInterface
    {
        return OffsetPaginator::make()->withDefaultLimit(50)->withMaxPerPage(250);
    }
}
