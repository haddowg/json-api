<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Server;

use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Pagination\PaginatorInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Server\Server;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Server::class)]
final class RelationPaginatorTest extends TestCase
{
    #[Test]
    public function theRelationsOwnPaginatorWins(): void
    {
        $own = PagePaginator::make()->withDefaultPerPage(3);
        $server = $this->server();

        self::assertSame($own, $server->relationPaginator(HasMany::make('items', 'paged-items')->paginate($own)->build()));
        self::assertSame($own, $server->linkagePaginator(HasMany::make('items', 'paged-items')->paginate($own)->build()));
    }

    #[Test]
    public function theRelatedResourcesPaginatorComesNext(): void
    {
        $paginator = $this->server()->relationPaginator(HasMany::make('items', 'paged-items')->build());

        self::assertInstanceOf(PagePaginator::class, $paginator);
        self::assertSame(7, $this->defaultSize($paginator));
    }

    #[Test]
    public function theServerDefaultComesLast(): void
    {
        $server = $this->server();

        self::assertSame($server->defaultPaginator(), $server->relationPaginator(HasMany::make('notes', 'notes')->build()));
    }

    #[Test]
    public function aPolymorphicRelationSkipsTheRelatedResource(): void
    {
        $server = $this->server();

        self::assertSame($server->defaultPaginator(), $server->relationPaginator(MorphToMany::make('things', ['paged-items'])->build()));
    }

    #[Test]
    public function aToOneOrAnUnpaginatedRelationHasNone(): void
    {
        $server = $this->server();

        self::assertNull($server->relationPaginator(BelongsTo::make('owner', 'notes')->build()));
        self::assertNull($server->relationPaginator(HasMany::make('items', 'paged-items')->withoutPagination()->build()));
    }

    #[Test]
    public function aRelationWithNoEndpointKeepsItsWholeLinkage(): void
    {
        $relation = HasMany::make('items', 'paged-items')->withoutRelatedEndpoint()->withoutRelationshipEndpoint()->build();
        $server = $this->server();

        self::assertNotNull($server->relationPaginator($relation));
        self::assertNull($server->linkagePaginator($relation));
        self::assertNotNull($server->linkagePaginator(HasMany::make('items', 'paged-items')->withoutRelationshipEndpoint()->build()));
    }

    private function server(): Server
    {
        return Server::make()
            ->withDefaultPaginator(PagePaginator::make()->withDefaultPerPage(15))
            ->register(PagedItemResource::class)
            ->register(NoteResource::class);
    }

    private function defaultSize(PaginatorInterface $paginator): int
    {
        $window = $paginator->window(new \haddowg\JsonApi\Tests\Double\StubJsonApiRequest());
        \assert($window instanceof \haddowg\JsonApi\Pagination\OffsetWindow);

        return $window->limit;
    }
}

final class PagedItemResource extends AbstractResource
{
    public static string $type = 'paged-items';

    public function pagination(?PaginatorInterface $serverDefault): PaginatorInterface
    {
        return PagePaginator::make()->withDefaultPerPage(7);
    }

    public function fields(): array
    {
        return [Id::make(), Str::make('title')];
    }
}

final class NoteResource extends AbstractResource
{
    public static string $type = 'notes';

    public function fields(): array
    {
        return [Id::make(), Str::make('body')];
    }
}
