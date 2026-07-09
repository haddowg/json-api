<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Pagination;

use haddowg\JsonApi\Exception\PaginationKindUnknown;
use haddowg\JsonApi\Pagination\CursorBasedPage;
use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Pagination\MultiPaginator;
use haddowg\JsonApi\Pagination\OffsetBasedPage;
use haddowg\JsonApi\Pagination\OffsetPaginator;
use haddowg\JsonApi\Pagination\PageBasedPage;
use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:pagination')]
final class MultiPaginatorTest extends TestCase
{
    private function menu(): MultiPaginator
    {
        return MultiPaginator::make(
            PagePaginator::make(),
            OffsetPaginator::make(),
            CursorPaginator::make(),
        )->default('cursor');
    }

    #[Test]
    public function anExplicitKindDiscriminatorSelectsThatChild(): void
    {
        $request = StubJsonApiRequest::create(['page' => ['kind' => 'offset', 'limit' => '5']]);

        self::assertInstanceOf(OffsetPaginator::class, $this->menu()->resolve($request));
    }

    #[Test]
    public function anUnknownKindIsA400ListingTheValidKinds(): void
    {
        $request = StubJsonApiRequest::create(['page' => ['kind' => 'keyset']]);

        try {
            $this->menu()->resolve($request);
            self::fail('Expected PaginationKindUnknown');
        } catch (PaginationKindUnknown $e) {
            self::assertSame(400, $e->getStatusCode());
            self::assertSame('keyset', $e->kind);
            self::assertSame(['page', 'offset', 'cursor'], $e->validKinds);
            $error = $e->getErrors()[0];
            self::assertSame('PAGINATION_KIND_UNKNOWN', $error->code);
            self::assertStringContainsString('page, offset, cursor', (string) $error->detail);
        }
    }

    #[Test]
    public function aStrategyUniqueKeySelectsWithoutADiscriminator(): void
    {
        // `after`/`before` are unique to cursor; `offset`/`limit` unique to offset.
        self::assertInstanceOf(
            CursorPaginator::class,
            $this->menu()->resolve(StubJsonApiRequest::create(['page' => ['after' => 'abc']])),
        );
        self::assertInstanceOf(
            OffsetPaginator::class,
            $this->menu()->resolve(StubJsonApiRequest::create(['page' => ['offset' => '10']])),
        );
        // `number` is unique to the page strategy across this menu.
        self::assertInstanceOf(
            PagePaginator::class,
            $this->menu()->resolve(StubJsonApiRequest::create(['page' => ['number' => '2']])),
        );
    }

    #[Test]
    public function aSharedKeyOnlyRequestFallsBackToTheDefault(): void
    {
        // `size` is shared between page and cursor, so it does not select alone — the
        // declared default (cursor) wins.
        self::assertInstanceOf(
            CursorPaginator::class,
            $this->menu()->resolve(StubJsonApiRequest::create(['page' => ['size' => '10']])),
        );
    }

    #[Test]
    public function anAbsentPageFallsBackToTheDefault(): void
    {
        self::assertInstanceOf(CursorPaginator::class, $this->menu()->resolve(StubJsonApiRequest::create([])));
    }

    #[Test]
    public function theFirstDeclaredChildIsTheDefaultWhenNoneIsDeclared(): void
    {
        $menu = MultiPaginator::make(PagePaginator::make(), CursorPaginator::make());

        self::assertInstanceOf(PagePaginator::class, $menu->resolve(StubJsonApiRequest::create([])));
    }

    #[Test]
    public function defaultingToAnUnknownKindIsAWiringError(): void
    {
        $this->expectException(\LogicException::class);

        MultiPaginator::make(PagePaginator::make())->default('cursor');
    }

    #[Test]
    public function twoChildrenWithTheSameKindIsAWiringError(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/Duplicate paginator kind/');

        MultiPaginator::make(PagePaginator::make(), PagePaginator::make());
    }

    #[Test]
    public function anEmptyMenuIsAWiringError(): void
    {
        $this->expectException(\LogicException::class);

        MultiPaginator::make();
    }

    #[Test]
    public function customKindsLetTwoOfTheSameStrategyCoexist(): void
    {
        $menu = MultiPaginator::make(
            PagePaginator::make()->withDefaultPerPage(10),
            PagePaginator::make()->withDefaultPerPage(50)->withKind('bulk'),
        )->default('bulk');

        $bulk = $menu->resolve(StubJsonApiRequest::create(['page' => ['kind' => 'bulk']]));
        self::assertInstanceOf(PagePaginator::class, $bulk);
        self::assertSame(50, $bulk->defaultPerPage);
    }

    #[Test]
    public function windowAndPaginateSelfResolveToTheSelectedChild(): void
    {
        $menu = $this->menu();

        $cursorRequest = StubJsonApiRequest::create(['page' => ['after' => '']]);
        self::assertInstanceOf(CursorBasedPage::class, $menu->paginate($cursorRequest, [], 0));

        $offsetRequest = StubJsonApiRequest::create(['page' => ['kind' => 'offset']]);
        self::assertInstanceOf(OffsetBasedPage::class, $menu->paginate($offsetRequest, [], 0));

        $pageRequest = StubJsonApiRequest::create(['page' => ['number' => '1']]);
        self::assertInstanceOf(PageBasedPage::class, $menu->paginateWithoutCount($pageRequest, [], false));
    }

    #[Test]
    public function theMenuReportsItsKindsAndChildren(): void
    {
        $menu = $this->menu();

        self::assertSame(['page', 'offset', 'cursor'], $menu->kinds());
        self::assertCount(3, $menu->children());
        self::assertSame('multi', $menu->kind());
        self::assertSame('menu', $menu->withKind('menu')->kind());
    }

    #[Test]
    public function theProjectedSchemaEncodesTheSelectionRule(): void
    {
        // Validate representative page objects against the projected oneOf schema —
        // the schema itself is what makes a shared-key-only object ambiguous.
        $schema = $this->menu()->describePageSchema()->toJson();
        $validator = new Validator();

        // A unique-key object matches exactly one branch.
        self::assertTrue($validator->validate((object) ['after' => 'abc'], $schema)->isValid());
        self::assertTrue($validator->validate((object) ['offset' => 10], $schema)->isValid());
        self::assertTrue($validator->validate((object) ['number' => 2], $schema)->isValid());

        // A shared-key-only object matches several branches → invalid until `kind`.
        self::assertFalse($validator->validate((object) ['size' => 10], $schema)->isValid());

        // Adding the discriminator disambiguates it.
        self::assertTrue($validator->validate((object) ['kind' => 'cursor', 'size' => 10], $schema)->isValid());
        self::assertTrue($validator->validate((object) ['kind' => 'page', 'size' => 10], $schema)->isValid());

        // A key no branch reads is rejected (additionalProperties: false).
        self::assertFalse($validator->validate((object) ['bogus' => 1], $schema)->isValid());
    }
}
