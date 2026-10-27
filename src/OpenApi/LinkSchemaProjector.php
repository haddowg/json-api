<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

use haddowg\JsonApi\OpenApi\Metadata\ActionMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresActionLink;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresActionLinks;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresLinkObjects;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresResourceLinks;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresWriteOnlyRelation;
use haddowg\JsonApi\OpenApi\Metadata\RelationMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApi\Pagination\MultiPaginator;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Link\DeclaredLink;

/**
 * Projects the `links` schemas: the shared link shapes, and one links component per
 * context (a type's resource object, single document and collection document, and each
 * relationship object) listing the members that context renders.
 *
 * Each member of a per-context component carries an example built from the document's
 * first server URL as it stands and the member's path template, with the type's id
 * example (else `1`) in place of `{id}`. When the server renders link objects and names
 * its document URL, the example's `describedby` points at the operation the member's
 * target is, checked against the projected paths so it never names a missing one.
 *
 * @internal
 */
final readonly class LinkSchemaProjector
{
    private const PAGINATION = ['first', 'prev', 'next', 'last'];

    private const EXAMPLE_CURSOR_AFTER = 'eyJpZCI6MjB9';

    private const EXAMPLE_CURSOR_BEFORE = 'eyJpZCI6MTF9';

    private const HREF_NOTE = ' The example uses the document\'s server URL; in a response, `href` is absolute to the request host unless the server pins its links to a configured base URI.';

    private bool $objects;

    private string $serverUrl;

    private ?SpecDescribedByResolver $describedBy;

    private ?TypeMetadataInterface $exampleType;

    public function __construct(ServerMetadataInterface $server, Paths $paths)
    {
        $this->exampleType = $server->types()[0] ?? null;

        $this->objects = !$server instanceof DeclaresLinkObjects || $server->rendersLinkObjects();

        $servers = $server->servers();
        $this->serverUrl = $servers === [] ? '' : \rtrim($servers[0]->url, '/');

        $documentUrl = $server instanceof DeclaresLinkObjects ? $server->documentUrl() : null;
        $this->describedBy = $documentUrl !== null && $documentUrl !== ''
            ? SpecDescribedByResolver::fromPaths($documentUrl, $paths)
            : null;
    }

    /**
     * The shared components: the open `LinkObject` (any link, in either form), and, when
     * the server renders link objects, the `RenderedLink` object form its built links
     * take.
     *
     * @return array<string, Schema>
     */
    public function sharedComponents(): array
    {
        $components = ['LinkObject' => $this->linkObjectSchema()];
        if ($this->objects) {
            $components['RenderedLink'] = $this->renderedLinkSchema();
        }

        $components['Links'] = $this->topLevelLinksSchema();

        return $components;
    }

    /**
     * The per-context links components for one type, keyed by component name.
     *
     * @return array<string, Schema>
     */
    public function typeComponents(TypeMetadataInterface $type, string $base, SchemaProjector $fields = new SchemaProjector(), ?EnumComponentCollector $collector = null): array
    {
        $uriType = $type->uriType();
        $resourcePath = $this->examplePath(PathTemplate::resource($uriType), $type);
        $collectionPath = $this->examplePath(PathTemplate::collection($uriType), $type);

        $components = [];

        $resource = Schema::ofType('object')
            ->withDescription(\sprintf('The `links` of a resource object of type `%s`.', $type->type()))
            ->withProperty('self', $this->member($resourcePath, OperationReference::fetchOne($uriType), 'The resource\'s own URL.'));

        $actionLinks = self::actionLinksOf($type);

        foreach (self::declaredLinksOf($type) as $link) {
            if (isset($actionLinks[$link->name])) {
                throw new \LogicException(\sprintf(
                    'Type "%s" declares the link "%s", which its "%s" action also renders as a resource link. Rename one of them.',
                    $type->type(),
                    $link->name,
                    $actionLinks[$link->name]['action']->path(),
                ));
            }
            $resource = $resource->withProperty($link->name, $this->declaredLinkSchema($link, $fields, $collector));
        }

        foreach ($actionLinks as $name => ['action' => $action, 'description' => $description]) {
            $resource = $resource->withProperty($name, $this->member(
                $this->examplePath(PathTemplate::action($uriType, $action->path(), ActionScope::Resource), $type),
                OperationReference::action($uriType, $action->path(), ActionScope::Resource, $action->methods()[0]),
                $description ?? \sprintf('The `%s` action on this resource.', $action->path()),
            ));
        }
        $components[$base . 'ResourceLinks'] = self::withObjectExample($resource)->withAdditionalProperties(Schema::ref('#/components/schemas/LinkObject'));

        $document = Schema::ofType('object')
            ->withDescription(\sprintf('The top-level `links` of a document whose primary data is one resource of type `%s`.', $type->type()))
            ->withProperty('self', $this->member($resourcePath, OperationReference::fetchOne($uriType), 'The URL that produced the document.'));
        $document = $this->withDescribedByMember($document, OperationReference::fetchOne($uriType));
        $components[$base . 'DocumentLinks'] = self::withObjectExample($document)->withAdditionalProperties(Schema::ref('#/components/schemas/LinkObject'));

        $collectionOperation = OperationReference::fetchCollection($uriType);
        $collection = Schema::ofType('object')
            ->withDescription(\sprintf('The top-level `links` of a document whose primary data is a collection of type `%s`.', $type->type()))
            ->withProperty('self', $this->member($collectionPath, $collectionOperation, 'The URL that produced the document.'));
        $collection = $this->withDescribedByMember($collection, $collectionOperation);
        $pageQueries = self::pageExampleQueries($type->pageSchema());
        foreach (self::PAGINATION as $name) {
            $collection = $collection->withProperty($name, $this->member($collectionPath, $collectionOperation, \sprintf('The `%s` page of the collection, when there is one.', $name), $pageQueries[$name] ?? []));
        }
        $components[$base . 'CollectionLinks'] = self::withObjectExample($collection, self::unrenderedPages($pageQueries))
            ->withAdditionalProperties(Schema::ref('#/components/schemas/LinkObject'));

        foreach ($type->relations() as $relation) {
            $relBase = $base . ComponentNaming::base($relation->name());
            $components[$relBase . 'RelationshipLinks'] = $this->relationshipLinks($type, $relation);
            if ($relation->exposesRelationshipEndpoint()) {
                $components[$relBase . 'RelationshipDocumentLinks'] = $this->relationshipDocumentLinks($type, $relation);
            }
            if ($relation->exposesRelatedEndpoint() && !($relation instanceof DeclaresWriteOnlyRelation && $relation->isWriteOnly())) {
                $components[$relBase . 'RelatedDocumentLinks'] = $this->relatedDocumentLinks($type, $relation);
            }
        }

        return $components;
    }

    /**
     * The links a type's metadata declares, or none when it does not implement
     * {@see DeclaresResourceLinks}.
     *
     * @return list<DeclaredLink>
     */
    public static function declaredLinksOf(TypeMetadataInterface $type): array
    {
        return $type instanceof DeclaresResourceLinks ? $type->declaredLinks() : [];
    }

    /**
     * The resource-scope actions a type renders as resource links, keyed by link name, in
     * action order, each with the description its declared action link gives it. An action
     * links when it says so through {@see DeclaresActionLink}, or when the type declares
     * an action link for its path ({@see DeclaresActionLinks}); the declaration's
     * description then wins.
     *
     * An action link for a path that names no resource-scope action of the type fails, since
     * the document would describe a link the server can never render.
     *
     * @return array<string, array{action: ActionMetadataInterface, description: ?string}>
     */
    public static function actionLinksOf(TypeMetadataInterface $type): array
    {
        $declared = [];
        if ($type instanceof DeclaresActionLinks) {
            foreach ($type->actionLinks() as $link) {
                $declared[$link->path] = $link;
            }
        }

        $links = [];
        $resourceActions = [];
        foreach ($type->actions() as $action) {
            if ($action->scope() !== ActionScope::Resource) {
                continue;
            }
            $resourceActions[$action->path()] = true;
            $declaration = $declared[$action->path()] ?? null;
            $name = $declaration !== null
                ? $action->path()
                : ($action instanceof DeclaresActionLink ? $action->linkName() : null);
            if ($name === null || $name === '' || $action->methods() === []) {
                continue;
            }
            $links[$name] = ['action' => $action, 'description' => $declaration?->description];
        }

        foreach ($declared as $path => $link) {
            if (!isset($resourceActions[$path])) {
                throw new \LogicException(\sprintf(
                    'Type "%s" declares an action link for "%s", but it has no resource-scope action with that path.',
                    $type->type(),
                    $path,
                ));
            }
        }

        return $links;
    }

    /**
     * Whether any type of the server declares a link, which the document's generator
     * contract reflects ({@see GeneratorContract::DECLARED_LINKS}).
     */
    public static function serverDeclaresLinks(ServerMetadataInterface $server): bool
    {
        foreach ($server->types() as $type) {
            if (self::declaredLinksOf($type) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * One declared link's member of `<Type>ResourceLinks`. A link that renders as a bare
     * string takes the open `LinkObject` union, as any author-supplied link does. One that
     * renders as a link object gets its own object schema: `href`, each static member as a
     * `const`, and a `meta` object whose members follow the read rules an attribute does.
     */
    private function declaredLinkSchema(DeclaredLink $link, SchemaProjector $fields, ?EnumComponentCollector $collector): Schema
    {
        $description = $link->description ?? \sprintf('The `%s` link, absent when it does not apply to the resource.', $link->name);
        $example = self::declaredLinkExample($link);

        if (!$link->rendersAsObject()) {
            $schema = Schema::ref('#/components/schemas/LinkObject')->withDescription($description);

            return $example === null ? $schema : $schema->withExample($example);
        }

        $schema = Schema::ofType('object')
            ->withDescription($description)
            ->withProperty('href', Schema::ofType('string')->withFormat('uri-reference'));
        $required = ['href'];
        foreach ($link->staticMembers() as $member => $value) {
            $schema = $schema->withProperty($member, Schema::ofType('string')->withConst($value));
            $required[] = $member;
        }
        if ($link->meta !== []) {
            $meta = $fields->projectReadObject($link->meta, $collector);
            $schema = $schema->withProperty('meta', $meta);
            $metaRequired = $meta->get('required');
            if (\is_array($metaRequired) && $metaRequired !== []) {
                $required[] = 'meta';
            }
        }
        if ($link->describedBy !== null) {
            $schema = $schema->withProperty('describedby', Schema::ofType('string')->withFormat('uri-reference'));
            $required[] = 'describedby';
        }
        $schema = $schema->withRequired($required);

        return $example === null ? $schema : $schema->withExample($example);
    }

    /**
     * A declared link's example as a response renders it: the declared `href` example, and
     * for a link object its static members and every meta member's own example. `null`
     * when any of those is undeclared; nothing is invented in its place.
     *
     * @return string|array<string, mixed>|null
     */
    public static function declaredLinkExample(DeclaredLink $link): string|array|null
    {
        if ($link->example === null) {
            return null;
        }
        if (!$link->rendersAsObject()) {
            return $link->example;
        }

        $example = ['href' => $link->example, ...$link->staticMembers()];
        $meta = [];
        foreach ($link->meta as $member) {
            if ($member->isHidden() || $member->isWriteOnly()) {
                continue;
            }
            if (!$member->hasExample()) {
                return null;
            }
            $meta[$member->name()] = $member->getExample();
        }
        if ($meta !== []) {
            $example['meta'] = $meta;
        }
        if ($link->describedBy !== null) {
            $example['describedby'] = $link->describedBy;
        }

        return $example;
    }

    private function relationshipLinks(TypeMetadataInterface $type, RelationMetadataInterface $relation): Schema
    {
        $uriType = $type->uriType();
        $name = $relation->name();
        $segment = RelationFacts::uriFieldName($relation);
        $relationshipOperation = OperationReference::fetchRelationship($uriType, $segment);
        $relationshipPath = $this->examplePath(PathTemplate::relationship($uriType, $segment), $type);

        $schema = Schema::ofType('object')
            ->withDescription(\sprintf('The `links` of the `%s` relationship object of a resource of type `%s`.', $name, $type->type()));

        if ($relation->exposesRelationshipEndpoint()) {
            $schema = $schema->withProperty('self', $this->member($relationshipPath, $relationshipOperation, 'The relationship\'s own URL.'));
        }

        $writeOnly = $relation instanceof DeclaresWriteOnlyRelation && $relation->isWriteOnly();
        if ($relation->exposesRelatedEndpoint() && !$writeOnly) {
            $schema = $schema->withProperty('related', $this->member(
                $this->examplePath(PathTemplate::related($uriType, $segment), $type),
                OperationReference::fetchRelated($uriType, $segment),
                'The related resource data.',
            ));
        }

        $pageQueries = [];
        if ($relation->isToMany() && $relation->exposesRelationshipEndpoint() && $relation->pageSchema() !== null) {
            $pageQueries = self::pageExampleQueries($relation->pageSchema());
            foreach (self::PAGINATION as $page) {
                $schema = $schema->withProperty($page, $this->member($relationshipPath, $relationshipOperation, \sprintf('The `%s` page of the relationship\'s linkage, when there is one.', $page), $pageQueries[$page] ?? []));
            }
        }

        return self::withObjectExample($schema, self::unrenderedPages($pageQueries))
            ->withAdditionalProperties(Schema::ref('#/components/schemas/LinkObject'));
    }

    /**
     * The top-level `links` of the relationship endpoint's document. The server renders
     * the relationship object's own links there, so it lists the same members, plus the
     * document's `describedby`.
     */
    private function relationshipDocumentLinks(TypeMetadataInterface $type, RelationMetadataInterface $relation): Schema
    {
        $relationshipOperation = OperationReference::fetchRelationship($type->uriType(), RelationFacts::uriFieldName($relation));
        $links = $this->relationshipLinks($type, $relation)
            ->withDescription(\sprintf('The top-level `links` of the `%s` relationship endpoint\'s document for a resource of type `%s`.', $relation->name(), $type->type()));
        $links = $this->withDescribedByMember($links, $relationshipOperation);

        return self::withObjectExample($links, self::unrenderedPages(
            $relation->isToMany() && $relation->pageSchema() !== null ? self::pageExampleQueries($relation->pageSchema()) : [],
        ));
    }

    /**
     * The top-level `links` of the related endpoint's document: its own URL, its
     * `describedby` and, for a paginated to-many, the pages of the related collection.
     */
    private function relatedDocumentLinks(TypeMetadataInterface $type, RelationMetadataInterface $relation): Schema
    {
        $uriType = $type->uriType();
        $segment = RelationFacts::uriFieldName($relation);
        $relatedOperation = OperationReference::fetchRelated($uriType, $segment);
        $relatedPath = $this->examplePath(PathTemplate::related($uriType, $segment), $type);

        $schema = Schema::ofType('object')
            ->withDescription(\sprintf('The top-level `links` of the `%s` related endpoint\'s document for a resource of type `%s`.', $relation->name(), $type->type()))
            ->withProperty('self', $this->member($relatedPath, $relatedOperation, 'The URL that produced the document.'));
        $schema = $this->withDescribedByMember($schema, $relatedOperation);

        $pageQueries = [];
        if ($relation->isToMany() && $relation->pageSchema() !== null) {
            $pageQueries = self::pageExampleQueries($relation->pageSchema());
            foreach (self::PAGINATION as $page) {
                $schema = $schema->withProperty($page, $this->member($relatedPath, $relatedOperation, \sprintf('The `%s` page of the related collection, when there is one.', $page), $pageQueries[$page] ?? []));
            }
        }

        return self::withObjectExample($schema, self::unrenderedPages($pageQueries))
            ->withAdditionalProperties(Schema::ref('#/components/schemas/LinkObject'));
    }

    /**
     * Sets the links object's own `example` to its members' examples, so a sampler that
     * would otherwise invent `additionalProperties` entries shows the links a response
     * carries. `$omit` names members a response never renders together with the rest.
     *
     * @param list<string> $omit
     */
    private static function withObjectExample(Schema $links, array $omit = []): Schema
    {
        $properties = $links->get('properties');
        if (!\is_array($properties)) {
            return $links;
        }

        $example = [];
        foreach ($properties as $name => $member) {
            if (!$member instanceof Schema || \in_array($name, $omit, true)) {
                continue;
            }
            $value = $member->get('example');
            if ($value !== null) {
                $example[(string) $name] = $value;
            }
        }

        return $example === [] ? $links : $links->withExample($example);
    }

    /**
     * The pagination members a page with these example queries never renders: every one
     * the paginator gave no example for (all of them when it is unknown or absent).
     *
     * @param array<string, array<string, int|string>> $pageQueries
     *
     * @return list<string>
     */
    private static function unrenderedPages(array $pageQueries): array
    {
        return \array_values(\array_diff(self::PAGINATION, \array_keys($pageQueries)));
    }

    /**
     * Adds the top-level `describedby` member, present when the server describes its
     * documents (it names a document URL).
     */
    private function withDescribedByMember(Schema $schema, OperationReference $operation): Schema
    {
        $describedBy = $this->describedBy?->describedBy($operation);
        if ($describedBy === null) {
            return $schema;
        }

        $member = $this->objects
            ? Schema::ref('#/components/schemas/RenderedLink')->withExample(['href' => $describedBy])
            : Schema::ref('#/components/schemas/LinkObject')->withExample($describedBy);

        return $schema->withProperty('describedby', $member->withDescription('The OpenAPI operation that describes the document.'));
    }

    /**
     * One built link member: the link shape the server renders, with an example.
     *
     * @param array<string, int|string> $page the example's `page[…]` members, encoded the
     *                                        way a rendered pagination link encodes them
     */
    private function member(string $examplePath, OperationReference $operation, string $description, array $page = []): Schema
    {
        return Schema::ref('#/components/schemas/' . ($this->objects ? 'RenderedLink' : 'LinkObject'))
            ->withDescription($description . self::HREF_NOTE)
            ->withExample($this->linkExample($examplePath, $operation, $page));
    }

    /**
     * The example of one built link: its `href`, as an object carrying the target
     * operation's `describedby` when the server renders link objects, else the bare URL.
     *
     * @param array<string, int|string> $page
     *
     * @return string|array<string, string>
     */
    private function linkExample(string $examplePath, OperationReference $operation, array $page = []): string|array
    {
        return $this->renderedLink($examplePath, $operation, $page === [] ? [] : ['page' => $page]);
    }

    /**
     * A built link to `$path` under the document's first server URL, rendered the way the
     * server renders the links it builds: a link object carrying the target operation's
     * `describedby` when the server renders link objects, else the bare URL.
     *
     * @param array<string, mixed> $query
     *
     * @return string|array<string, string>
     */
    public function renderedLink(string $path, ?OperationReference $operation, array $query = []): string|array
    {
        $href = $this->serverUrl . $path . ($query === [] ? '' : '?' . \http_build_query($query));

        if (!$this->objects) {
            return $href;
        }

        $example = ['href' => $href];
        $describedBy = $operation === null ? null : $this->describedBy?->describedBy($operation);
        if ($describedBy !== null) {
            $example['describedby'] = $describedBy;
        }

        return $example;
    }

    /**
     * The top-level `describedby` of a document `$operation` produced, or `null` when the
     * server does not describe its documents.
     *
     * @return string|array<string, string>|null
     */
    public function documentDescribedBy(OperationReference $operation): string|array|null
    {
        $describedBy = $this->describedBy?->describedBy($operation);
        if ($describedBy === null) {
            return null;
        }

        return $this->objects ? ['href' => $describedBy] : $describedBy;
    }

    /**
     * Example `page[…]` members for each pagination link, read from the page schema's
     * `x-paginator` extension so they use the paginator's configured keys and default
     * size. The examples show the links of page two. A menu uses its default strategy,
     * whose links carry no `page[kind]`. A strategy the schema doesn't describe gets
     * none, and so does a cursor's `last`, which a cursor page never renders.
     *
     * @return array<string, array<string, int|string>>
     */
    public static function pageExampleQueries(?Schema $pageSchema): array
    {
        if ($pageSchema === null) {
            return [];
        }

        $paginator = $pageSchema->extension('paginator');
        if (\is_array($paginator) && ($paginator['strategy'] ?? null) === 'multi') {
            $pageSchema = self::defaultMenuBranch($pageSchema, $paginator['default'] ?? null);
            $paginator = $pageSchema?->extension('paginator');
        }
        if ($pageSchema === null || !\is_array($paginator) || !\is_array($paginator['keys'] ?? null)) {
            return [];
        }

        /** @var array<string, mixed> $keys */
        $keys = $paginator['keys'];
        $key = static fn(string $role): ?string => isset($keys[$role]) && \is_string($keys[$role]) ? $keys[$role] : null;
        $size = static function (?string $sizeKey) use ($pageSchema): array {
            if ($sizeKey === null) {
                return [];
            }
            $default = self::propertyDefault($pageSchema, $sizeKey);

            return $default === null ? [] : [$sizeKey => $default];
        };

        switch ($paginator['strategy'] ?? null) {
            case 'page':
            case 'fixed':
                $number = $key('number');
                if ($number === null) {
                    return [];
                }
                $sized = $size($key('size'));

                return [
                    'first' => [$number => 1, ...$sized],
                    'prev' => [$number => 1, ...$sized],
                    'next' => [$number => 3, ...$sized],
                    'last' => [$number => 5, ...$sized],
                ];
            case 'offset':
                $offset = $key('offset');
                $limitKey = $key('limit');
                if ($offset === null || $limitKey === null) {
                    return [];
                }
                $limit = self::propertyDefault($pageSchema, $limitKey) ?? 10;

                return [
                    'first' => [$offset => 0, $limitKey => $limit],
                    'prev' => [$offset => 0, $limitKey => $limit],
                    'next' => [$offset => 2 * $limit, $limitKey => $limit],
                    'last' => [$offset => 4 * $limit, $limitKey => $limit],
                ];
            case 'cursor':
                $after = $key('after');
                $before = $key('before');
                if ($after === null || $before === null) {
                    return [];
                }
                $sized = $size($key('size'));

                return [
                    'first' => $sized,
                    'prev' => [$before => self::EXAMPLE_CURSOR_BEFORE, ...$sized],
                    'next' => [$after => self::EXAMPLE_CURSOR_AFTER, ...$sized],
                ];
            default:
                return [];
        }
    }

    /**
     * The menu branch for the default strategy, or the first branch when no default
     * is named.
     */
    public static function defaultMenuBranch(Schema $menu, mixed $default): ?Schema
    {
        $branches = $menu->get('oneOf');
        if (!\is_array($branches) || $branches === []) {
            return null;
        }

        foreach ($branches as $branch) {
            if (!$branch instanceof Schema) {
                continue;
            }
            $properties = $branch->get('properties');
            $kind = \is_array($properties) && ($properties[MultiPaginator::KIND_KEY] ?? null) instanceof Schema
                ? $properties[MultiPaginator::KIND_KEY]->get('const')
                : null;
            if ($kind === $default) {
                return $branch;
            }
        }

        $first = \reset($branches);

        return $first instanceof Schema ? $first : null;
    }

    /**
     * The integer `default` of one property of a page schema, when it declares one.
     */
    public static function propertyDefault(Schema $pageSchema, string $property): ?int
    {
        $properties = $pageSchema->get('properties');
        $schema = \is_array($properties) ? ($properties[$property] ?? null) : null;
        $default = $schema instanceof Schema ? $schema->get('default') : null;

        return \is_int($default) ? $default : null;
    }

    /**
     * A path template with the type's example id in place of `{id}`.
     */
    private function examplePath(string $template, TypeMetadataInterface $type): string
    {
        return \str_replace(PathTemplate::ID, \rawurlencode($this->exampleId($type)), $template);
    }

    private function exampleId(TypeMetadataInterface $type): string
    {
        foreach ($type->fields() as $field) {
            if (!$field instanceof Id || !$field->hasExample()) {
                continue;
            }

            $example = $field->getExample();
            if (\is_string($example) && $example !== '' || \is_int($example)) {
                return (string) $example;
            }
        }

        return '1';
    }

    /**
     * Any JSON:API link: a URI string, a link object, or `null` (an absent link).
     */
    private function linkObjectSchema(): Schema
    {
        $object = Schema::ofType('object')
            ->withProperty('href', Schema::ofType('string')->withFormat('uri-reference'))
            ->withProperty('rel', Schema::ofType('string'))
            ->withProperty('describedby', Schema::create()
                ->withDescription('A link to a description document for the link\'s target.')
                ->withAnyOf([
                    Schema::ofType('string')->withFormat('uri-reference'),
                    Schema::ofType('object')
                        ->withProperty('href', Schema::ofType('string')->withFormat('uri-reference'))
                        ->withRequired(['href']),
                ]))
            ->withProperty('title', Schema::ofType('string'))
            ->withProperty('type', Schema::ofType('string'))
            ->withProperty('hreflang', Schema::create()->withAnyOf([
                Schema::ofType('string'),
                Schema::ofType('array')->withItems(Schema::ofType('string')),
            ]))
            ->withProperty('meta', Schema::ofType('object'))
            ->withRequired(['href']);

        // The first type's resource URL, as a resource object's `self` renders it.
        $type = $this->exampleType;
        $root = $this->serverUrl . '/';
        $example = $type === null
            ? ($this->objects ? ['href' => $root] : $root)
            : $this->linkExample($this->examplePath(PathTemplate::resource($type->uriType()), $type), OperationReference::fetchOne($type->uriType()));

        return Schema::create()
            ->withDescription('A JSON:API link. `null` marks a link that does not apply, such as `next` on the last page.')
            ->withExample($example)
            ->withAnyOf([
                Schema::ofType('string')->withFormat('uri-reference'),
                $object,
                Schema::ofType('null'),
            ]);
    }

    /**
     * The link object form the server renders the links it builds in.
     */
    private function renderedLinkSchema(): Schema
    {
        return Schema::ofType('object')
            ->withDescription('A link the server builds, rendered as a JSON:API link object. `describedby` is a URI-reference to the OpenAPI operation that describes the link\'s target, present when the server describes its links.')
            ->withProperty('href', Schema::ofType('string')->withFormat('uri-reference'))
            ->withProperty('describedby', Schema::ofType('string')->withFormat('uri-reference'))
            ->withProperty('meta', Schema::ofType('object'))
            ->withRequired(['href']);
    }

    /**
     * The open top-level `links` member of a document whose links aren't specific to
     * one type: `self` / `related` / `describedby` plus arbitrary further links.
     */
    private function topLevelLinksSchema(): Schema
    {
        $link = Schema::ref('#/components/schemas/LinkObject');
        $href = $this->serverUrl . '/';

        return Schema::ofType('object')
            ->withProperty('self', $link)
            ->withProperty('related', $link)
            ->withProperty('describedby', $link)
            ->withAdditionalProperties($link)
            // Without an example, samplers invent extra links from `additionalProperties`.
            ->withExample(['self' => $this->objects ? ['href' => $href] : $href]);
    }

}
