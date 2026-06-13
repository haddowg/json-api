<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Examples\MusicCatalog\Http;

use haddowg\JsonApi\Exception\ResourceNotFound;
use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Server\ServerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A toy PSR-15 router: it parses the request path into an
 * {@see \haddowg\JsonApi\Operation\Target} and attaches it as a request attribute
 * keyed by `Target::class` — the single routing attribute the
 * {@see \haddowg\JsonApi\Operation\Psr7ToOperationHandlerAdapter} reads. In a real
 * app your framework's router does this; the library is router-agnostic and only
 * needs the `Target` attribute present.
 *
 * It recognises the four JSON:API endpoint shapes:
 *  - `/{type}`                              — a collection / create endpoint;
 *  - `/{type}/{id}`                         — a single-resource endpoint;
 *  - `/{type}/{id}/{relationship}`          — a related-resource endpoint;
 *  - `/{type}/{id}/relationships/{relationship}` — a relationship-linkage endpoint.
 *
 * It also enforces a per-type **operation allow-list** for the standalone,
 * read-only `charts` type: only `GET` is routable, so a `POST`/`PATCH`/`DELETE`
 * to `/charts` (or `/charts/{id}`) never produces a `Target` and is answered with
 * a `404` — exactly as a framework router that registered only the read routes for
 * that type would 404 the unregistered verbs. Any path that matches no shape is a
 * `404` too (a genuine no-match), so a missing route never reaches the adapter as
 * the `500` it would render for an unattached `Target`.
 */
final readonly class PathPrefixRouter implements MiddlewareInterface
{
    /**
     * Types whose operation allow-list is fetch-only (no write endpoints are
     * routed). The standalone `charts` bare serializer is read-only: it has no
     * hydrator, so a write could not be served even if routed.
     *
     * @var list<string>
     */
    private const FETCH_ONLY_TYPES = ['charts'];

    public function __construct(private ServerInterface $server) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $target = $this->route($request);

        if ($target === null) {
            // No route matched (an unknown path shape, or a write to a fetch-only
            // type): answer a clean 404 exactly as a real router's no-match would,
            // rather than letting the adapter render the 500 it produces for a
            // missing Target.
            return ErrorResponse::fromException(new ResourceNotFound())
                ->toPsrResponse($this->server, $request);
        }

        return $handler->handle($request->withAttribute(Target::class, $target));
    }

    private function route(ServerRequestInterface $request): ?Target
    {
        $segments = $this->segments($request->getUri()->getPath());
        $count = \count($segments);
        if ($count === 0) {
            return null;
        }

        $type = $segments[0];

        if ($this->isFetchOnly($type) && \strtoupper($request->getMethod()) !== 'GET') {
            return null;
        }

        return match ($count) {
            // /{type}
            1 => new Target($type),
            // /{type}/{id}
            2 => new Target($type, $segments[1]),
            // /{type}/{id}/{relationship}
            3 => new Target($type, $segments[1], $segments[2], isRelationshipEndpoint: false),
            // /{type}/{id}/relationships/{relationship}
            4 => $segments[2] === 'relationships'
                ? new Target($type, $segments[1], $segments[3], isRelationshipEndpoint: true)
                : null,
            default => null,
        };
    }

    private function isFetchOnly(string $type): bool
    {
        return \in_array($type, self::FETCH_ONLY_TYPES, true);
    }

    /**
     * Splits a URL path into its non-empty, URL-decoded segments.
     *
     * @return list<string>
     */
    private function segments(string $path): array
    {
        return \array_values(\array_map(
            '\rawurldecode',
            \array_filter(\explode('/', $path), static fn(string $segment): bool => $segment !== ''),
        ));
    }
}
