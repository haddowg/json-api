<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Response;

use haddowg\JsonApi\Pagination\Page;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Response\Internal\RenderedDocument;
use haddowg\JsonApi\Schema\Document\CollectionDocument;
use haddowg\JsonApi\Schema\Document\SingleResourceDocument;
use haddowg\JsonApi\Schema\Link\Link;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApi\Server\ServerInterface;
use haddowg\JsonApi\Transformer\DocumentTransformer;
use haddowg\JsonApi\Transformer\ResourceDocumentTransformation;

/**
 * The common-case response: a document whose primary `data` is a resource or a
 * collection of resources, rendered through a {@see SerializerInterface}.
 *
 * Single vs collection is fixed at construction by the named constructor used
 * ({@see fromResource()} / {@see fromCollection()}) rather than inferred from the
 * runtime shape of the data — an iterable single resource is therefore never
 * mistaken for a collection. The domain data and its resource serializer are not
 * withable; the document-level members ({@see withMeta()} etc.) are.
 */
final class DataResponse extends AbstractResponse
{
    /**
     * @param Page<mixed>|null $page
     */
    private function __construct(
        private readonly mixed $data,
        private readonly SerializerInterface $resource,
        private readonly bool $isCollection,
        private readonly ?Page $page = null,
    ) {}

    /**
     * A single-resource response whose `data` is the resource object.
     */
    public static function fromResource(mixed $object, SerializerInterface $resource): self
    {
        return new self($object, $resource, false);
    }

    /**
     * A collection response whose `data` is a list of resource objects.
     *
     * @param iterable<mixed> $objects
     */
    public static function fromCollection(iterable $objects, SerializerInterface $resource): self
    {
        return new self($objects, $resource, true);
    }

    /**
     * A paginated collection response: the `data` is the page's items, and the
     * document gains the pagination `links.{first,prev,next,last}` and
     * `meta.page` the {@see Page} emits. A page that activates a profile (e.g.
     * cursor pagination) causes the response to advertise it.
     *
     * @template T
     *
     * @param Page<T> $page
     */
    public static function fromPage(Page $page, SerializerInterface $resource): self
    {
        return new self($page, $resource, true, $page);
    }

    protected function render(ServerInterface $server, JsonApiRequestInterface $request): RenderedDocument
    {
        $document = $this->isCollection
            ? new CollectionDocument($this->resource, $this->resolveJsonApi($server), $this->meta, $this->links)
            : new SingleResourceDocument($this->resource, $this->resolveJsonApi($server), $this->meta, $this->links);

        $transformation = new ResourceDocumentTransformation(
            $document,
            $this->data,
            $request,
            '',
            '',
            [],
        );

        $result = (new DocumentTransformer())->transformResourceDocument($transformation)->result;

        if ($this->page !== null) {
            $result = $this->applyPagination($result, $server, $request, $this->page);
        }

        return new RenderedDocument($result, 200);
    }

    /**
     * Merges the page's pagination links and `meta.page` into the rendered body.
     * Links are absolute (built from the request's self URI + query string), so
     * they are injected post-transform rather than through the base-URI-prefixing
     * `DocumentLinks` path.
     *
     * @param array<string, mixed> $result
     * @param Page<mixed>          $page
     *
     * @return array<string, mixed>
     */
    private function applyPagination(array $result, ServerInterface $server, JsonApiRequestInterface $request, Page $page): array
    {
        $uri = $server->baseUri() . $request->getUri()->getPath();
        $queryString = $request->getUri()->getQuery();

        /** @var array<string, mixed> $links */
        $links = $result['links'] ?? [];
        foreach ($page->linkSet($uri, $queryString) as $rel => $link) {
            if ($link instanceof Link) {
                $links[$rel] = $link->transform('');
            }
        }
        if ($links !== []) {
            $result['links'] = $links;
        }

        $pageMeta = $page->pageMeta();
        if ($pageMeta !== []) {
            /** @var array<string, mixed> $meta */
            $meta = $result['meta'] ?? [];
            $existingPage = $meta['page'] ?? [];
            $meta['page'] = [...(\is_array($existingPage) ? $existingPage : []), ...$pageMeta];
            $result['meta'] = $meta;
        }

        return $result;
    }

    /**
     * Adds the page's profile to the applied set, on top of the request-requested
     * registered profiles — but only when the server **recognises** it. A page
     * must not advertise a profile the server has not registered; an unrecognized
     * page profile is silently dropped, mirroring the advisory treatment of
     * request-requested profiles. The registered instance is used (not the page's
     * own), so the server's configuration of that profile wins.
     */
    protected function appliedProfiles(ServerInterface $server, JsonApiRequestInterface $request): array
    {
        $profiles = parent::appliedProfiles($server, $request);

        $pageProfile = $this->page?->profile();
        if ($pageProfile === null) {
            return $profiles;
        }

        $registered = $server->profiles()->get($pageProfile->uri());
        if ($registered === null) {
            return $profiles;
        }

        foreach ($profiles as $profile) {
            if ($profile->uri() === $registered->uri()) {
                return $profiles;
            }
        }

        \array_unshift($profiles, $registered);

        return $profiles;
    }
}
