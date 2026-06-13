<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Examples\MusicCatalog\Serializer;

use haddowg\JsonApi\Examples\MusicCatalog\Domain\Chart;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\AbstractSerializer;
use haddowg\JsonApi\Serializer\UriTypeAwareInterface;

/**
 * A standalone, hand-written serializer for the `charts` type — the
 * capability-composition witness. There is **no** `charts` Resource and **no**
 * `charts` hydrator: the type is read-only, registered by a type-string key via
 * `Server::registerSerializerHydrator('charts', serializer: ChartSerializer::class)`
 * (contrast `register(AlbumResource::class)`, which is keyed by class-string and
 * supplies both read and write capabilities from one declaration).
 *
 * Because nothing else is registered for `charts`:
 *  - `hasSerializerFor('charts')` is true but `hasHydratorFor('charts')` is false
 *    (the read/write resolver mirror — the decoupling boundary);
 *  - `resourceFor('charts')` throws
 *    {@see \haddowg\JsonApi\Exception\NoResourceRegistered}, since there is no
 *    Resource object backing the type;
 *  - the host's operation allow-list routes only `GET /charts` and
 *    `GET /charts/{id}`; a write to `/charts` is never reached.
 *
 * It implements {@see UriTypeAwareInterface} so its URI path segment resolves
 * explicitly to `charts` (a bare serializer without it would fall back to
 * {@see getType()}). The seven {@see SerializerInterface} methods are written by
 * hand — `getAttributes()`/`getRelationships()` return **maps of callables**, each
 * invoked only for the members that survive sparse-fieldset filtering. The trait
 * helpers from {@see AbstractSerializer} are available but unused here (the chart's
 * attributes are plain scalars + a list of entry rows).
 */
final class ChartSerializer extends AbstractSerializer implements UriTypeAwareInterface
{
    public function uriType(): string
    {
        return 'charts';
    }

    public function getType(mixed $object): string
    {
        return 'charts';
    }

    public function getId(mixed $object): string
    {
        \assert($object instanceof Chart);

        return $object->id;
    }

    public function getMeta(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }

    public function getLinks(mixed $object, JsonApiRequestInterface $request): ?ResourceLinks
    {
        return null;
    }

    public function getAttributes(mixed $object, JsonApiRequestInterface $request): array
    {
        return [
            'name' => static fn(mixed $chart, JsonApiRequestInterface $request, string $field): string
                => $chart instanceof Chart ? $chart->name : '',
            'period' => static fn(mixed $chart, JsonApiRequestInterface $request, string $field): string
                => $chart instanceof Chart ? $chart->period : '',
            'entries' => static fn(mixed $chart, JsonApiRequestInterface $request, string $field): array
                => $chart instanceof Chart ? $chart->entries : [],
        ];
    }

    public function getDefaultIncludedRelationships(mixed $object): array
    {
        return [];
    }

    public function getRelationships(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }
}
