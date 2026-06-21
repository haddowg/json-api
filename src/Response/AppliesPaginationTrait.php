<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Response;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\Link\Link;
use haddowg\JsonApi\Server\ServerInterface;

/**
 * Shared pagination application for the collection responses that carry a
 * {@see \haddowg\JsonApi\Pagination\PageInterface}. Both {@see DataResponse}
 * (the primary collection) and {@see RelatedResponse} (a related to-many
 * collection) merge the page's links and `meta.page` the same way — scoped to
 * the request's own self URI — and advertise the page's profile identically.
 */
trait AppliesPaginationTrait
{
    /**
     * Merges the page's pagination links and `meta.page` into the rendered body.
     * Links are absolute (built from the request's self URI + query string), so
     * they are injected post-transform rather than through the base-URI-prefixing
     * `DocumentLinks` path. The self URI is the path of the endpoint the client
     * actually hit (the primary collection, or the related-collection URL such as
     * `/articles/1/comments`), preserving the request's query string across pages.
     *
     * @param array<string, mixed> $result
     * @param \haddowg\JsonApi\Pagination\PageInterface<mixed> $page
     *
     * @return array<string, mixed>
     */
    private function applyPagination(array $result, ServerInterface $server, JsonApiRequestInterface $request, \haddowg\JsonApi\Pagination\PageInterface $page): array
    {
        $uri = \haddowg\JsonApi\Server\RequestBaseUri::resolve($server->baseUri(), $request->getUri()) . $request->getUri()->getPath();
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
     *
     * @param list<\haddowg\JsonApi\Schema\Profile\ProfileInterface> $profiles
     * @param \haddowg\JsonApi\Pagination\PageInterface<mixed>|null  $page
     *
     * @return list<\haddowg\JsonApi\Schema\Profile\ProfileInterface>
     */
    private function appliedPageProfiles(array $profiles, ServerInterface $server, ?\haddowg\JsonApi\Pagination\PageInterface $page): array
    {
        $pageProfile = $page?->profile();
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
