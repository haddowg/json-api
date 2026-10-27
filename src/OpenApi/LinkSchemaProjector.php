<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

use haddowg\JsonApi\OpenApi\Metadata\ActionMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresActionLink;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresActionLinks;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresLinkObjects;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresResourceLinks;
use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApi\Pagination\MultiPaginator;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Link\DeclaredLink;

/**
 * Projects the `links` schemas: the shared link shapes, one shared links component per
 * context (a resource object, a single document, a collection document, a relationship
 * object, and the relationship and related endpoints' documents) listing the members that
 * context can render, and a `<Type>ResourceLinks` for a type with named links, extending
 * the shared `ResourceLinks` with them.
 *
 * The link schemas carry no examples. The operation examples show each document whole,
 * links included, built by {@see ExampleDocumentBuilder} through {@see renderedLink()}.
 *
 * @internal
 */
final readonly class LinkSchemaProjector
{
    public const RESOURCE_LINKS = 'ResourceLinks';

    public const DOCUMENT_LINKS = 'DocumentLinks';

    public const COLLECTION_LINKS = 'CollectionLinks';

    public const RELATIONSHIP_LINKS = 'RelationshipLinks';

    public const RELATIONSHIP_DOCUMENT_LINKS = 'RelationshipDocumentLinks';

    public const RELATED_DOCUMENT_LINKS = 'RelatedDocumentLinks';

    private const PAGINATION = ['first', 'prev', 'next', 'last'];

    private const EXAMPLE_CURSOR_AFTER = 'eyJpZCI6MjB9';

    private const EXAMPLE_CURSOR_BEFORE = 'eyJpZCI6MTF9';

    private const HREF_NOTE = ' In a response, `href` is absolute to the request host unless the server pins its links to a configured base URI.';

    private bool $objects;

    private string $serverUrl;

    private ?SpecDescribedByResolver $describedBy;

    private ?TypeMetadataInterface $exampleType;

    private bool $relations;

    private bool $relationshipEndpoints;

    private bool $relatedEndpoints;

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

        $relations = $relationshipEndpoints = $relatedEndpoints = false;
        foreach ($server->types() as $type) {
            foreach ($type->relations() as $relation) {
                $relations = true;
                $relationshipEndpoints = $relationshipEndpoints || $relation->exposesRelationshipEndpoint();
                $relatedEndpoints = $relatedEndpoints || ($relation->exposesRelatedEndpoint() && !RelationFacts::isWriteOnly($relation));
            }
        }
        $this->relations = $relations;
        $this->relationshipEndpoints = $relationshipEndpoints;
        $this->relatedEndpoints = $relatedEndpoints;
    }

    /**
     * The shared components: the open `LinkObject` (any link, in either form), the
     * `RenderedLink` object form built links take when the server renders link objects,
     * the open `Links`, and one links component per context some type of the server
     * renders.
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

        if ($this->exampleType === null) {
            return $components;
        }

        $components[self::RESOURCE_LINKS] = $this->resourceLinks();
        $components[self::DOCUMENT_LINKS] = $this->documentLinks();
        $components[self::COLLECTION_LINKS] = $this->collectionLinks();
        if ($this->relations) {
            $components[self::RELATIONSHIP_LINKS] = $this->relationshipLinks();
        }
        if ($this->relationshipEndpoints) {
            $components[self::RELATIONSHIP_DOCUMENT_LINKS] = $this->relationshipDocumentLinks();
        }
        if ($this->relatedEndpoints) {
            $components[self::RELATED_DOCUMENT_LINKS] = $this->relatedDocumentLinks();
        }

        return $components;
    }

    /**
     * The links component a type's resource objects `$ref`: its own `<Type>ResourceLinks`
     * when it has named links, else the shared `ResourceLinks`.
     */
    public static function resourceLinksComponent(TypeMetadataInterface $type, string $base): string
    {
        return self::hasNamedLinks($type) ? $base . self::RESOURCE_LINKS : self::RESOURCE_LINKS;
    }

    /**
     * A type's own links component, emitted only when it has named links: an `allOf` of
     * the shared `ResourceLinks` and an object holding one property per declared link and
     * action link.
     *
     * @return array<string, Schema>
     */
    public function typeComponents(TypeMetadataInterface $type, string $base, SchemaProjector $fields = new SchemaProjector(), ?EnumComponentCollector $collector = null): array
    {
        if (!self::hasNamedLinks($type)) {
            return [];
        }

        $named = Schema::ofType('object');
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
            $named = $named->withProperty($link->name, $this->declaredLinkSchema($link, $fields, $collector));
        }

        foreach ($actionLinks as $name => ['action' => $action, 'description' => $description]) {
            $named = $named->withProperty($name, $this->member($description ?? \sprintf('The `%s` action on this resource.', $action->path())));
        }

        return [$base . self::RESOURCE_LINKS => Schema::create()
            ->withDescription(\sprintf('The `links` of a resource object of type `%s`.', $type->type()))
            ->withAllOf([Schema::ref(ComponentNaming::schemaRef(self::RESOURCE_LINKS)), $named]),
        ];
    }

    private static function hasNamedLinks(TypeMetadataInterface $type): bool
    {
        return self::declaredLinksOf($type) !== [] || self::actionLinksOf($type) !== [];
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
     * An action link for a path that names no resource-scope action of the type on this
     * server is left out, since this server never renders it. An adapter checks that some
     * server serving the type has the action, which a single server's projection can't see.
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
        foreach ($type->actions() as $action) {
            if ($action->scope() !== ActionScope::Resource) {
                continue;
            }
            $declaration = $declared[$action->path()] ?? null;
            $name = $declaration !== null
                ? $action->path()
                : ($action instanceof DeclaresActionLink ? $action->linkName() : null);
            if ($name === null || $name === '' || $action->methods() === []) {
                continue;
            }
            $links[$name] = ['action' => $action, 'description' => $declaration?->description];
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

        if (!$link->rendersAsObject()) {
            return Schema::ref('#/components/schemas/LinkObject')->withDescription($description);
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
        return $schema->withRequired($required);
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

    /**
     * The `links` of a resource object.
     */
    private function resourceLinks(): Schema
    {
        return $this->linksObject('The `links` of a resource object.')
            ->withProperty('self', $this->member('The resource\'s own URL.'))
            ->withAdditionalProperties(Schema::ref('#/components/schemas/LinkObject'));
    }

    /**
     * The top-level `links` of a document whose primary data is one resource.
     */
    private function documentLinks(): Schema
    {
        $schema = $this->linksObject('The top-level `links` of a document whose primary data is one resource.')
            ->withProperty('self', $this->member('The URL that produced the document.'));

        return $this->withDescribedByMember($schema)
            ->withAdditionalProperties(Schema::ref('#/components/schemas/LinkObject'));
    }

    /**
     * The top-level `links` of a document whose primary data is a collection.
     */
    private function collectionLinks(): Schema
    {
        $schema = $this->linksObject('The top-level `links` of a document whose primary data is a collection. The pages are present when the collection is paginated.')
            ->withProperty('self', $this->member('The URL that produced the document.'));

        return $this->withPages($this->withDescribedByMember($schema), 'The `%s` page of the collection, when there is one.')
            ->withAdditionalProperties(Schema::ref('#/components/schemas/LinkObject'));
    }

    /**
     * The `links` of a relationship object.
     */
    private function relationshipLinks(): Schema
    {
        return $this->withRelationshipMembers($this->linksObject('The `links` of a relationship object. `self` is present when the relationship endpoint is exposed, `related` when the related endpoint is, and the pages on a paginated to-many relationship.'))
            ->withAdditionalProperties(Schema::ref('#/components/schemas/LinkObject'));
    }

    /**
     * The top-level `links` of the relationship endpoint's document. The server renders
     * the relationship object's own links there, so it lists the same members, plus the
     * document's `describedby`.
     */
    private function relationshipDocumentLinks(): Schema
    {
        $schema = $this->withRelationshipMembers($this->linksObject('The top-level `links` of a relationship endpoint\'s document: the relationship object\'s own links, and the document\'s `describedby`. `related` is present when the related endpoint is exposed, and the pages on a paginated to-many relationship.'));

        return $this->withDescribedByMember($schema)
            ->withAdditionalProperties(Schema::ref('#/components/schemas/LinkObject'));
    }

    /**
     * The top-level `links` of a related endpoint's document: its own URL, its
     * `describedby` and, for a paginated to-many, the pages of the related collection.
     */
    private function relatedDocumentLinks(): Schema
    {
        $schema = $this->linksObject('The top-level `links` of a related endpoint\'s document. The pages are present on a paginated to-many relationship.')
            ->withProperty('self', $this->member('The URL that produced the document.'));

        return $this->withPages($this->withDescribedByMember($schema), 'The `%s` page of the related collection, when there is one.')
            ->withAdditionalProperties(Schema::ref('#/components/schemas/LinkObject'));
    }

    private function linksObject(string $description): Schema
    {
        return Schema::ofType('object')->withDescription($description);
    }

    private function withRelationshipMembers(Schema $schema): Schema
    {
        $schema = $schema
            ->withProperty('self', $this->member('The relationship\'s own URL.'))
            ->withProperty('related', $this->member('The related resource data.'));

        return $this->withPages($schema, 'The `%s` page of the relationship\'s linkage, when there is one.');
    }

    private function withPages(Schema $schema, string $description): Schema
    {
        foreach (self::PAGINATION as $page) {
            $schema = $schema->withProperty($page, $this->member(\sprintf($description, $page)));
        }

        return $schema;
    }

    /**
     * Adds the top-level `describedby` member, present when the server describes its
     * documents (it names a document URL).
     */
    private function withDescribedByMember(Schema $schema): Schema
    {
        if ($this->describedBy === null) {
            return $schema;
        }

        return $schema->withProperty('describedby', $this->member('The OpenAPI operation that describes the document.'));
    }

    /**
     * One built link member: the link shape the server renders.
     */
    private function member(string $description): Schema
    {
        return Schema::ref('#/components/schemas/' . ($this->objects ? 'RenderedLink' : 'LinkObject'))
            ->withDescription($description);
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
            : $this->renderedLink($this->examplePath(PathTemplate::resource($type->uriType()), $type), OperationReference::fetchOne($type->uriType()));

        return Schema::create()
            ->withDescription('A JSON:API link. `null` marks a link that does not apply, such as `next` on the last page.' . ($this->objects ? '' : self::HREF_NOTE))
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
            ->withDescription('A link the server builds, rendered as a JSON:API link object. `describedby` is a URI-reference to the OpenAPI operation that describes the link\'s target, present when the server describes its links.' . self::HREF_NOTE)
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
