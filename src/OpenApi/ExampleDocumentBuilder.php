<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

use haddowg\JsonApi\Exception\ErrorDescriptor;
use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresActionLink;
use haddowg\JsonApi\OpenApi\Metadata\RelationMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApi\Pagination\CursorPaginationProfile;
use haddowg\JsonApi\Resource\Field\AbstractFieldValue;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\OneOf;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Schema\Profile\CountableProfile;

/**
 * Builds the example documents an operation's request and response media types carry,
 * from the examples the server's fields and error descriptors declare. Nothing is
 * invented: a value the document needs and no declaration supplies is recorded as a miss,
 * and {@see attempt()} then returns no document at all.
 *
 * The documents mirror what the server renders: member order, the links it builds (in
 * its link form, with their `describedby`), and the pagination links and `meta.page` its
 * paginators write. A collection shows page two of a larger set holding one resource.
 *
 * @internal
 */
final class ExampleDocumentBuilder
{
    /**
     * The `page[after]` of the cursor page a collection example shows: the page holding
     * the rows between the two cursors {@see LinkSchemaProjector::pageExampleQueries()}
     * links to.
     */
    private const CURSOR_REQUEST = 'eyJpZCI6MTB9';

    /** @var array<string, TypeMetadataInterface> */
    private array $types = [];

    /** @var array<string, array{string, string}> */
    private array $misses = [];

    public function __construct(
        private readonly ServerMetadataInterface $server,
        private readonly LinkSchemaProjector $links,
    ) {
        foreach ($server->types() as $type) {
            $this->types[$type->type()] = $type;
        }
    }

    /**
     * Runs `$build` and returns what it built with the misses it recorded, each a
     * `[type, field]` pair. The value is `null` whenever anything was missed.
     *
     * @template T
     *
     * @param \Closure(): T $build
     *
     * @return array{T|null, list<array{string, string}>}
     */
    public function attempt(\Closure $build): array
    {
        $previous = $this->misses;
        $this->misses = [];

        try {
            $value = $build();
            $misses = \array_values($this->misses);

            return [$misses === [] ? $value : null, $misses];
        } finally {
            $this->misses = $previous;
        }
    }

    public function type(string $type): ?TypeMetadataInterface
    {
        return $this->types[$type] ?? null;
    }

    // ---- Documents --------------------------------------------------------------

    /**
     * A document whose primary data is one `$type` resource, produced by a request to
     * `$path`.
     *
     * @return array<string, mixed>
     */
    public function resourceDocument(TypeMetadataInterface $type, string $path, ?OperationReference $operation, bool $selfLink = true): array
    {
        $document = ['data' => $this->resource($type)];
        $links = $selfLink ? ['self' => $this->links->renderedLink($path, $operation)] : [];

        return $this->finish($document, $this->withDescribedBy($links, $operation), []);
    }

    /**
     * A document whose primary data is a page of `$type` resources, produced by a
     * request to `$path`. `$countable` says whether the collection can carry its total,
     * which the example then shows, as a client asking for it with `withCount` gets it.
     *
     * @return array<string, mixed>
     */
    public function collectionDocument(TypeMetadataInterface $type, string $path, OperationReference $operation, ?Schema $pageSchema, bool $countable): array
    {
        $document = ['data' => [$this->resource($type)]];

        return $this->paginated($document, $path, $operation, $pageSchema, $countable, 1);
    }

    /**
     * The related endpoint's document for a to-one relation: the related resource.
     *
     * @return array<string, mixed>
     */
    public function relatedDocument(TypeMetadataInterface $owner, RelationMetadataInterface $relation): array
    {
        $segment = RelationFacts::uriFieldName($relation);
        $path = $this->path(PathTemplate::related($owner->uriType(), $segment), $this->id($owner));
        $operation = OperationReference::fetchRelated($owner->uriType(), $segment);
        $related = $this->relatedType($owner, $relation);

        if ($relation->isToMany()) {
            if ($related === null) {
                return [];
            }

            return $this->collectionDocument($related, $path, $operation, $relation->pageSchema(), $relation->isCountable());
        }

        return $this->finish(
            ['data' => $related === null ? null : $this->resource($related)],
            $this->withDescribedBy(['self' => $this->links->renderedLink($path, $operation)], $operation),
            [],
        );
    }

    /**
     * The relationship endpoint's document: the relation's linkage, with the links the
     * relationship object renders. A read of a paginated to-many is a page of linkage.
     *
     * @return array<string, mixed>
     */
    public function relationshipDocument(TypeMetadataInterface $owner, RelationMetadataInterface $relation, bool $reading): array
    {
        $id = $this->id($owner);
        $segment = RelationFacts::uriFieldName($relation);
        $operation = OperationReference::fetchRelationship($owner->uriType(), $segment);
        $path = $this->path(PathTemplate::relationship($owner->uriType(), $segment), $id);

        $linkage = $this->linkage($owner, $relation, RepresentationContext::Read);
        if ($linkage === false) {
            $this->miss($relation->relatedTypes()[0] ?? $relation->name(), 'id');
            $linkage = null;
        }

        $document = ['data' => $linkage];
        $links = $this->relationshipLinks($owner, $relation, $id);

        if ($reading && $relation->isToMany() && $relation->pageSchema() !== null) {
            $document['links'] = $links;

            return $this->paginated($document, $path, $operation, $relation->pageSchema(), $relation->isCountable(), \is_array($linkage) ? \count($linkage) : 0);
        }

        $links['self'] ??= $this->links->renderedLink($path, $operation);

        return $this->finish($document, $this->withDescribedBy($links, $operation), []);
    }

    /**
     * A relationship mutation's request document: the linkage to write.
     *
     * @return array<string, mixed>
     */
    public function relationshipRequest(TypeMetadataInterface $owner, RelationMetadataInterface $relation): array
    {
        $linkage = $this->linkage($owner, $relation, RepresentationContext::Update);
        if ($linkage === false) {
            $this->miss($relation->relatedTypes()[0] ?? $relation->name(), 'id');
            $linkage = null;
        }

        return ['data' => $linkage];
    }

    /**
     * A create or update request document for `$type`: the members a write may set.
     *
     * @return array<string, mixed>
     */
    public function writeDocument(TypeMetadataInterface $type, bool $creating): array
    {
        $context = $creating ? RepresentationContext::Create : RepresentationContext::Update;
        $resource = ['type' => $type->type()];
        if (!$creating || $type->allowsClientId() || $type->requiresClientId()) {
            $resource['id'] = $this->id($type);
        }

        $attributes = $this->attributes($type, $context);
        if ($attributes !== []) {
            $resource['attributes'] = $attributes;
        }

        $relationships = [];
        foreach ($type->relations() as $relation) {
            if (!$creating && !$relation->allowsReplace()) {
                continue;
            }
            $linkage = $this->linkage($type, $relation, $context);
            // A relation whose target the server does not register has no id to show, and
            // a write may leave any relationship out.
            if ($linkage !== false) {
                $relationships[$relation->name()] = ['data' => $linkage];
            }
        }
        if ($relationships !== []) {
            $resource['relationships'] = $relationships;
        }

        return ['data' => $resource];
    }

    /**
     * An error document carrying one error object per descriptor.
     *
     * @param list<ErrorDescriptor> $descriptors
     *
     * @return array<string, mixed>
     */
    public function errorDocument(array $descriptors): array
    {
        $errors = [];
        foreach ($descriptors as $descriptor) {
            $error = [
                'status' => (string) $descriptor->status,
                'code' => $descriptor->code,
                'title' => $descriptor->title,
            ];
            if ($descriptor->exampleDetail !== null) {
                $error['detail'] = $descriptor->exampleDetail;
            }
            if ($descriptor->source !== null) {
                if ($descriptor->exampleSource === null) {
                    $this->miss($descriptor->code, 'source');
                }
                $error['source'] = [$descriptor->source->value => $descriptor->exampleSource ?? ''];
            }
            $errors[] = $error;
        }

        return ['errors' => $errors, 'jsonapi' => $this->jsonApi([])];
    }

    // ---- Resource objects -------------------------------------------------------

    /**
     * A response resource object of `$type`: what a read renders with no sparse
     * fieldset, so a write-only or sparse-by-default member is left out.
     *
     * @return array<string, mixed>
     */
    public function resource(TypeMetadataInterface $type): array
    {
        $id = $this->id($type);
        $resource = [
            'type' => $type->type(),
            'id' => $id,
            'links' => $this->resourceLinks($type, $id),
        ];

        $attributes = $this->attributes($type, RepresentationContext::Read);
        if ($attributes !== []) {
            $resource['attributes'] = $attributes;
        }

        $relationships = [];
        foreach ($type->relations() as $relation) {
            if (RelationFacts::isWriteOnly($relation)) {
                continue;
            }
            $object = $this->relationshipLinks($type, $relation, $id);
            $object = $object === [] ? [] : ['links' => $object];
            $linkage = $this->linkage($type, $relation, RepresentationContext::Read);
            if ($linkage !== false) {
                $object['data'] = $linkage;
            }
            // A relationship object renders at least one member; one with neither links
            // nor a showable linkage is left out.
            if ($object !== []) {
                $relationships[$relation->name()] = $object;
            }
        }
        if ($relationships !== []) {
            $resource['relationships'] = $relationships;
        }

        return $resource;
    }

    /**
     * The id example of `$type`: its {@see Id} field's declared example.
     */
    public function id(TypeMetadataInterface $type): string
    {
        foreach ($type->fields() as $field) {
            if ($field instanceof Id && $field->hasExample()) {
                $example = $field->getExample();
                if (\is_string($example) && $example !== '' || \is_int($example)) {
                    return (string) $example;
                }
            }
        }

        $this->miss($type->type(), 'id');

        return '';
    }

    /**
     * A path template with `$id` in place of `{id}`.
     */
    public function path(string $template, string $id): string
    {
        return \str_replace(PathTemplate::ID, \rawurlencode($id), $template);
    }

    /**
     * @return array<string, mixed>
     */
    private function resourceLinks(TypeMetadataInterface $type, string $id): array
    {
        $uriType = $type->uriType();
        $links = ['self' => $this->links->renderedLink($this->path(PathTemplate::resource($uriType), $id), OperationReference::fetchOne($uriType))];

        foreach ($type->actions() as $action) {
            if (!$action instanceof DeclaresActionLink || $action->scope() !== ActionScope::Resource) {
                continue;
            }
            $name = $action->linkName();
            if ($name === null || $name === '' || $action->methods() === []) {
                continue;
            }
            $links[$name] = $this->links->renderedLink(
                $this->path(PathTemplate::action($uriType, $action->path(), ActionScope::Resource), $id),
                OperationReference::action($uriType, $action->path(), ActionScope::Resource, $action->methods()[0]),
            );
        }

        return $links;
    }

    /**
     * The by-convention links a relationship object renders.
     *
     * @return array<string, mixed>
     */
    private function relationshipLinks(TypeMetadataInterface $owner, RelationMetadataInterface $relation, string $id): array
    {
        $uriType = $owner->uriType();
        $segment = RelationFacts::uriFieldName($relation);
        $links = [];
        if ($relation->exposesRelationshipEndpoint()) {
            $links['self'] = $this->links->renderedLink(
                $this->path(PathTemplate::relationship($uriType, $segment), $id),
                OperationReference::fetchRelationship($uriType, $segment),
            );
        }
        if ($relation->exposesRelatedEndpoint() && !RelationFacts::isWriteOnly($relation)) {
            $links['related'] = $this->links->renderedLink(
                $this->path(PathTemplate::related($uriType, $segment), $id),
                OperationReference::fetchRelated($uriType, $segment),
            );
        }

        return $links;
    }

    /**
     * The related type a relation's example shows: its first, for a polymorphic one.
     */
    private function relatedType(TypeMetadataInterface $owner, RelationMetadataInterface $relation): ?TypeMetadataInterface
    {
        $related = $relation->relatedTypes()[0] ?? null;

        return $related === null ? null : $this->type($related);
    }

    /**
     * A relation's linkage `data`: one identifier of its (first) related type, in a list
     * for a to-many, carrying the pivot fields a pivot-backed relation renders or accepts
     * in `meta.pivot`. `false` when the related type is not registered here, so there is
     * no id to show.
     *
     * @return array<string, mixed>|list<array<string, mixed>>|false
     */
    private function linkage(TypeMetadataInterface $owner, RelationMetadataInterface $relation, RepresentationContext $context): array|false
    {
        $related = $this->relatedType($owner, $relation);
        if ($related === null) {
            return false;
        }

        $identifier = ['type' => $related->type(), 'id' => $this->id($related)];
        if (RelationFacts::hasPivot($relation)) {
            $pivot = [];
            foreach ($relation->pivotFields() as $field) {
                if (!$this->appears($field, $context === RepresentationContext::Read ? RepresentationContext::Read : null)) {
                    continue;
                }
                $pivot[$field->name()] = $this->value($field, $context, $owner->type(), $relation->name() . '.pivot.' . $field->name());
            }
            $identifier['meta'] = ['pivot' => $pivot === [] ? new \stdClass() : $pivot];
        }

        return $relation->isToMany() ? [$identifier] : $identifier;
    }

    /**
     * The attributes of `$type` in one representation, keyed by member name.
     *
     * @return array<string, mixed>
     */
    private function attributes(TypeMetadataInterface $type, RepresentationContext $context): array
    {
        $attributes = [];
        foreach ($type->fields() as $field) {
            if ($field instanceof Id || $field instanceof RelationInterface || !$this->appears($field, $context)) {
                continue;
            }
            if ($context === RepresentationContext::Read && $field->isSparseByDefault()) {
                continue;
            }
            $attributes[$field->name()] = $this->value($field, $context, $type->type(), $field->name());
        }

        return $attributes;
    }

    /**
     * Whether a field appears in a representation. `null` stands for any write, the way a
     * pivot field one of the two writes may set is accepted on a relationship mutation.
     */
    private function appears(FieldInterface $field, ?RepresentationContext $context): bool
    {
        if ($field->isHidden()) {
            return false;
        }

        return match ($context) {
            RepresentationContext::Read => !$field->isWriteOnly(),
            RepresentationContext::Create => !$field->isReadOnly(true),
            RepresentationContext::Update => !$field->isReadOnly(false),
            null => !$field->isReadOnly(true) || !$field->isReadOnly(false),
        };
    }

    /**
     * A field's example value: its own declared example, else for a nested object one
     * built from the children the representation shows, else a miss.
     */
    private function value(FieldInterface $field, RepresentationContext $context, string $type, string $path): mixed
    {
        if ($field->hasExample()) {
            return $field->getExample();
        }

        // A read hook shapes the value itself, so its children say nothing about it.
        $hooked = $context === RepresentationContext::Read && $field instanceof AbstractFieldValue && $field->rendersThroughHook();

        if (!$hooked && $field instanceof OneOf) {
            foreach ($field->variants() as $variant => $shape) {
                return [$field->discriminatorName() => $variant, ...$this->nested($shape->children(), $context, $type, $path)];
            }
        }

        if (!$hooked && ($field instanceof Obj || $field instanceof Map) && $field->children() !== []) {
            $nested = $this->nested($field->children(), $context, $type, $path);

            return $nested === [] ? new \stdClass() : $nested;
        }

        $this->miss($type, $path);

        return null;
    }

    /**
     * @param list<FieldInterface> $children
     *
     * @return array<string, mixed>
     */
    private function nested(array $children, RepresentationContext $context, string $type, string $path): array
    {
        $nested = [];
        foreach ($children as $child) {
            if ($this->appears($child, $context)) {
                $nested[$child->name()] = $this->value($child, $context, $type, $path . '.' . $child->name());
            }
        }

        return $nested;
    }

    // ---- Pagination and document members ----------------------------------------

    /**
     * Adds the links and `meta` a paginated read renders to `$document`, for the page two
     * of a collection a request to `$path` produced. An unpaginated collection is fetched
     * whole, so it always carries its total.
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function paginated(array $document, string $path, OperationReference $operation, ?Schema $pageSchema, bool $countable, int $count): array
    {
        /** @var array<string, mixed> $links */
        $links = $document['links'] ?? [];
        unset($document['links']);

        if ($pageSchema === null) {
            $links['self'] = $this->links->renderedLink($path, $operation);

            return $this->finish($document, $this->withDescribedBy($links, $operation), ['total' => $count]);
        }

        $data = $document['data'] ?? null;
        $first = \is_array($data) && \is_array($data[0] ?? null) ? $data[0]['id'] ?? null : null;
        $page = $this->page($pageSchema, $countable && \in_array(CountableProfile::URI, $this->server->profiles(), true), \is_string($first) ? $first : null);
        if ($page === null) {
            $links['self'] = $this->links->renderedLink($path, $operation);

            return $this->finish($document, $this->withDescribedBy($links, $operation), []);
        }

        $query = $page['request'];
        if ($page['total'] !== null) {
            $query[CountableProfile::FAMILY] = CountableProfile::SELF_TOKEN;
        }
        foreach ($page['links'] as $name => $members) {
            $links[$name] = $this->links->renderedLink($path, $operation, [...$query, 'page' => $members]);
        }
        $links['self'] ??= $this->links->renderedLink($path, $operation, $query);

        $meta = $page['total'] === null ? [] : [CountableProfile::META_MEMBER => $page['total']];
        $meta['page'] = $page['meta'];

        $profiles = [];
        if ($page['total'] !== null) {
            $profiles[] = CountableProfile::URI;
        }
        if ($page['profile'] !== null && \in_array($page['profile'], $this->server->profiles(), true)) {
            $profiles[] = $page['profile'];
        }

        return $this->finish($document, $this->withDescribedBy($links, $operation), $meta, $profiles);
    }

    /**
     * What page two of a paginated collection renders under the page schema's strategy:
     * the request's `page` members, the page's links (in the order the page lists them)
     * and its `meta.page`. A counted page (`$counted`) also renders `last` and the total.
     * A cursor page names the first and last rows it holds by id, here `$firstId` for
     * both, since the example shows one. `null` for a strategy the schema does not name, whose page the projector cannot
     * show.
     *
     * @return array{request: array<string, mixed>, links: array<string, array<string, int|string>>, meta: array<string, mixed>, total: int|null, profile: string|null}|null
     */
    private function page(Schema $pageSchema, bool $counted, ?string $firstId): ?array
    {
        $paginator = $pageSchema->extension('paginator');
        if (\is_array($paginator) && ($paginator['strategy'] ?? null) === 'multi') {
            $pageSchema = LinkSchemaProjector::defaultMenuBranch($pageSchema, $paginator['default'] ?? null);
            $paginator = $pageSchema?->extension('paginator');
        }
        if ($pageSchema === null || !\is_array($paginator) || !\is_array($paginator['keys'] ?? null)) {
            return null;
        }

        $queries = LinkSchemaProjector::pageExampleQueries($pageSchema);
        /** @var array<string, mixed> $keys */
        $keys = $paginator['keys'];
        $key = static fn(string $role): ?string => isset($keys[$role]) && \is_string($keys[$role]) ? $keys[$role] : null;

        switch ($paginator['strategy'] ?? null) {
            case 'page':
                $number = $key('number');
                $sizeKey = $key('size');
                $size = $sizeKey === null ? null : LinkSchemaProjector::propertyDefault($pageSchema, $sizeKey);
                if ($number === null || $sizeKey === null || $size === null || $queries === []) {
                    return null;
                }
                $current = [$number => 2, $sizeKey => $size];
                $meta = ['currentPage' => 2, 'perPage' => $size, 'from' => $size + 1, 'to' => 2 * $size];
                if ($counted) {
                    $meta += ['total' => 5 * $size, 'lastPage' => 5];
                }

                return $this->pageResult($current, $queries, $meta, $counted ? 5 * $size : null);
            case 'fixed':
                // A fixed page's size is the server's and the schema does not carry it, so
                // the total it would render cannot be shown: the example is count-free.
                $number = $key('number');
                if ($number === null || $queries === []) {
                    return null;
                }

                return $this->pageResult([$number => 2], $queries, ['currentPage' => 2], null);
            case 'offset':
                $offset = $key('offset');
                $limitKey = $key('limit');
                if ($offset === null || $limitKey === null || $queries === []) {
                    return null;
                }
                $limit = (int) ($queries['first'][$limitKey] ?? 10);
                $meta = ['offset' => $limit, 'limit' => $limit, 'from' => $limit + 1, 'to' => 2 * $limit];
                if ($counted) {
                    $meta['total'] = 5 * $limit;
                }

                return $this->pageResult([$offset => $limit, $limitKey => $limit], $queries, $meta, $counted ? 5 * $limit : null);
            case 'cursor':
                $after = $key('after');
                if ($after === null || $queries === []) {
                    return null;
                }
                $sizeKey = $key('size');
                $size = $sizeKey === null ? null : LinkSchemaProjector::propertyDefault($pageSchema, $sizeKey);
                $request = [$after => self::CURSOR_REQUEST, ...($sizeKey !== null && $size !== null ? [$sizeKey => $size] : [])];
                $meta = $size === null ? [] : ['perPage' => $size];
                if ($firstId !== null) {
                    $meta['from'] = $firstId;
                    $meta['to'] = $firstId;
                }
                $meta['hasMore'] = true;

                return [
                    'request' => ['page' => $request],
                    'links' => $queries,
                    'meta' => $meta,
                    'total' => null,
                    'profile' => CursorPaginationProfile::URI,
                ];
            default:
                return null;
        }
    }

    /**
     * A count-based page: `self` leads its links, and `last` renders only when counted.
     *
     * @param array<string, int|string>                $current the page's own `page` members
     * @param array<string, array<string, int|string>> $queries
     * @param array<string, mixed>                     $meta
     *
     * @return array{request: array<string, mixed>, links: array<string, array<string, int|string>>, meta: array<string, mixed>, total: int|null, profile: string|null}
     */
    private function pageResult(array $current, array $queries, array $meta, ?int $total): array
    {
        $links = ['self' => $current];
        foreach (['first', 'prev', 'next', 'last'] as $name) {
            if (isset($queries[$name]) && ($name !== 'last' || $total !== null)) {
                $links[$name] = $queries[$name];
            }
        }

        return [
            'request' => ['page' => $current],
            'links' => $links,
            'meta' => $meta,
            'total' => $total,
            'profile' => null,
        ];
    }

    /**
     * @param array<string, mixed> $links
     *
     * @return array<string, mixed>
     */
    private function withDescribedBy(array $links, ?OperationReference $operation): array
    {
        $describedBy = $operation === null ? null : $this->links->documentDescribedBy($operation);
        if ($describedBy !== null) {
            $links['describedby'] = $describedBy;
        }

        return $links;
    }

    /**
     * Completes a document with its `links`, `meta` and `jsonapi` members, in the order the
     * server renders them.
     *
     * @param array<string, mixed> $document
     * @param array<string, mixed> $links
     * @param array<string, mixed> $meta
     * @param list<string>         $profiles the profiles the response applies
     *
     * @return array<string, mixed>
     */
    private function finish(array $document, array $links, array $meta, array $profiles = []): array
    {
        if ($links !== []) {
            $document['links'] = $links;
        }
        if ($meta !== []) {
            $document['meta'] = $meta;
        }
        $document['jsonapi'] = $this->jsonApi($profiles);

        return $document;
    }

    /**
     * @param list<string> $profiles
     *
     * @return array<string, mixed>
     */
    private function jsonApi(array $profiles): array
    {
        $jsonApi = ['version' => $this->server->jsonApiVersion()];
        if ($profiles !== []) {
            $jsonApi['profile'] = $profiles;
        }

        return $jsonApi;
    }

    private function miss(string $type, string $field): void
    {
        $this->misses[$type . "\0" . $field] = [$type, $field];
    }
}
