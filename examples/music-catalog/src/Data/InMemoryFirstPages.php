<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Examples\MusicCatalog\Data;

use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Pagination\PageInterface;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Request\RelatedQuery;
use haddowg\JsonApi\Resource\Field\PolymorphicRelationInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Schema\Relationship\RelationshipLinkage;
use haddowg\JsonApi\Schema\Relationship\RelationshipPagination;
use haddowg\JsonApi\Serializer\RelationshipLinkageInterface;
use haddowg\JsonApi\Serializer\RelationshipPaginationInterface;
use haddowg\JsonApi\Server\Server;

/**
 * Cuts every rendered to-many to the page its related endpoint serves: the first page
 * wherever its linkage renders, included or declared `withData()`, and the requested
 * page on its own relationship endpoint. Core renders the page's members as the linkage
 * and `included`, and its links in the relationship object.
 *
 * The page is read through {@see InMemoryRepository::fetchRelatedCollection()}, the
 * read the related endpoint runs, under {@see Server::linkagePaginator()}. A real
 * adapter would read every parent's first page in one query instead.
 */
final class InMemoryFirstPages implements RelationshipLinkageInterface, RelationshipPaginationInterface
{
    private ?Server $server = null;

    /**
     * The request a relationship endpoint reads its own relation's page with, by parent,
     * or `null` to render its whole set.
     *
     * @var \WeakMap<object, array<string, JsonApiRequestInterface|null>>
     */
    private \WeakMap $requested;

    /**
     * The pages already read, by parent then relation name.
     *
     * @var \WeakMap<object, array<string, PageInterface<mixed>|null>>
     */
    private \WeakMap $pages;

    public function __construct(private readonly InMemoryRepository $repository)
    {
        $this->requested = new \WeakMap();
        $this->pages = new \WeakMap();
    }

    /**
     * Forgets the pages read for the previous operation.
     */
    public function forget(): void
    {
        $this->requested = new \WeakMap();
        $this->pages = new \WeakMap();
    }

    /**
     * The server whose paginators cut the pages.
     */
    public function bind(Server $server): void
    {
        $this->server = $server;
    }

    /**
     * Reads `$relation` on `$parent` at the page `$request` asks for, as its relationship
     * endpoint renders it, rather than at its first page.
     */
    public function pageAt(object $parent, string $relation, JsonApiRequestInterface $request): void
    {
        $this->requested[$parent] = [...($this->requested[$parent] ?? []), $relation => $request];
        unset($this->pages[$parent]);
    }

    /**
     * Renders `$relation` on `$parent` whole, as a relationship mutation answers.
     */
    public function wholeAt(object $parent, string $relation): void
    {
        $this->requested[$parent] = [...($this->requested[$parent] ?? []), $relation => null];
        unset($this->pages[$parent]);
    }

    public function linkageForRelationship(mixed $model, RelationInterface $relation, JsonApiRequestInterface $request): ?RelationshipLinkage
    {
        $page = $this->page($model, $relation, $request);

        return $page === null ? null : new RelationshipLinkage(\iterator_to_array($page, false));
    }

    public function paginateRelationship(mixed $model, RelationInterface $relation, JsonApiRequestInterface $request): ?RelationshipPagination
    {
        $page = $this->page($model, $relation, $request);
        if ($page === null) {
            return null;
        }

        // This repository counts every page, but the total is shown only where a client
        // or the paginator asked for one.
        // A page the client asked to count links to pages that count too.
        $total = $page->pageMeta()['total'] ?? null;
        $requested = $request->countsRelationship($relation->name());
        $counted = $requested || ($this->server?->linkagePaginator($relation)?->wantsCount() ?? false);

        return new RelationshipPagination(
            $page,
            (new RelatedQuery())->toPlainQueryString(counted: $requested),
            $counted && \is_int($total) ? $total : null,
        );
    }

    /**
     * @return PageInterface<mixed>|null
     */
    private function page(mixed $model, RelationInterface $relation, JsonApiRequestInterface $request): ?PageInterface
    {
        if (!\is_object($model) || $this->server === null) {
            return null;
        }

        $paginator = $this->server->linkagePaginator($relation);
        if ($paginator === null) {
            return null;
        }

        $read = $this->pages[$model] ?? [];
        if (\array_key_exists($relation->name(), $read)) {
            return $read[$relation->name()];
        }

        $requested = $this->requested[$model] ?? [];
        if (\array_key_exists($relation->name(), $requested) && $requested[$relation->name()] === null) {
            return null;
        }

        $related = $relation->readValue($model, $request);
        $types = $relation->relatedTypes();
        $polymorphic = $relation instanceof PolymorphicRelationInterface || \count($types) !== 1;
        $resource = !$polymorphic && $this->server->hasResourceFor($types[0]) ? $this->server->resourceFor($types[0]) : null;
        $pageRequest = $requested[$relation->name()] ?? RelationshipPagination::firstPageRequest($request);

        $result = $this->repository->fetchRelatedCollection(
            \is_iterable($related) ? $related : [],
            $resource,
            QueryParameters::fromRequest($pageRequest),
            $pageRequest,
            $paginator,
            applyCriteria: !$polymorphic,
        );

        $page = $result instanceof PageInterface ? $result : null;
        $this->pages[$model] = [...$read, $relation->name() => $page];

        return $page;
    }
}
