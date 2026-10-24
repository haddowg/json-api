<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresActionLink;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresLinkObjects;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresWriteOnlyRelation;
use haddowg\JsonApi\OpenApi\Metadata\RelationMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApi\Resource\Field\Id;

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

    private const HREF_NOTE = ' The example uses the document\'s server URL; in a response, `href` is absolute to the request host unless the server pins its links to a configured base URI.';

    private bool $objects;

    private string $serverUrl;

    private ?SpecDescribedByResolver $describedBy;

    public function __construct(ServerMetadataInterface $server, Paths $paths)
    {
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
        $components['PaginationLinks'] = $this->paginationLinksSchema();

        return $components;
    }

    /**
     * The per-context links components for one type, keyed by component name.
     *
     * @return array<string, Schema>
     */
    public function typeComponents(TypeMetadataInterface $type, string $base): array
    {
        $uriType = $type->uriType();
        $resourcePath = $this->examplePath(PathTemplate::resource($uriType), $type);
        $collectionPath = $this->examplePath(PathTemplate::collection($uriType), $type);

        $components = [];

        $resource = Schema::ofType('object')
            ->withDescription(\sprintf('The `links` of a resource object of type `%s`.', $type->type()))
            ->withProperty('self', $this->member($resourcePath, OperationReference::fetchOne($uriType), 'The resource\'s own URL.'));
        foreach ($type->actions() as $action) {
            if (!$action instanceof DeclaresActionLink || $action->scope() !== ActionScope::Resource) {
                continue;
            }
            $name = $action->linkName();
            if ($name === null || $name === '' || $action->methods() === []) {
                continue;
            }
            $resource = $resource->withProperty($name, $this->member(
                $this->examplePath(PathTemplate::action($uriType, $action->path(), ActionScope::Resource), $type),
                OperationReference::action($uriType, $action->path(), ActionScope::Resource, $action->methods()[0]),
                \sprintf('The `%s` action on this resource.', $action->path()),
            ));
        }
        $components[$base . 'ResourceLinks'] = $resource->withAdditionalProperties(Schema::ref('#/components/schemas/LinkObject'));

        $document = Schema::ofType('object')
            ->withDescription(\sprintf('The top-level `links` of a document whose primary data is one resource of type `%s`.', $type->type()))
            ->withProperty('self', $this->member($resourcePath, OperationReference::fetchOne($uriType), 'The URL that produced the document.'));
        $document = $this->withDescribedByMember($document, OperationReference::fetchOne($uriType));
        $components[$base . 'DocumentLinks'] = $document->withAdditionalProperties(Schema::ref('#/components/schemas/LinkObject'));

        $collectionOperation = OperationReference::fetchCollection($uriType);
        $collection = Schema::ofType('object')
            ->withDescription(\sprintf('The top-level `links` of a document whose primary data is a collection of type `%s`.', $type->type()))
            ->withProperty('self', $this->member($collectionPath, $collectionOperation, 'The URL that produced the document.'));
        $collection = $this->withDescribedByMember($collection, $collectionOperation);
        foreach (self::PAGINATION as $name) {
            $collection = $collection->withProperty($name, $this->member($collectionPath, $collectionOperation, \sprintf('The `%s` page of the collection, when there is one.', $name)));
        }
        $components[$base . 'CollectionLinks'] = $collection->withAdditionalProperties(Schema::ref('#/components/schemas/LinkObject'));

        foreach ($type->relations() as $relation) {
            $components[$base . ComponentNaming::base($relation->name()) . 'RelationshipLinks'] = $this->relationshipLinks($type, $relation);
        }

        return $components;
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

        if ($relation->isToMany() && $relation->exposesRelationshipEndpoint() && $relation->pageSchema() !== null) {
            foreach (self::PAGINATION as $page) {
                $schema = $schema->withProperty($page, $this->member($relationshipPath, $relationshipOperation, \sprintf('The `%s` page of the relationship\'s linkage, when there is one.', $page)));
            }
        }

        return $schema->withAdditionalProperties(Schema::ref('#/components/schemas/LinkObject'));
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
     */
    private function member(string $examplePath, OperationReference $operation, string $description): Schema
    {
        $href = $this->serverUrl . $examplePath;

        if (!$this->objects) {
            return Schema::ref('#/components/schemas/LinkObject')
                ->withDescription($description . self::HREF_NOTE)
                ->withExample($href);
        }

        $example = ['href' => $href];
        $describedBy = $this->describedBy?->describedBy($operation);
        if ($describedBy !== null) {
            $example['describedby'] = $describedBy;
        }

        return Schema::ref('#/components/schemas/RenderedLink')
            ->withDescription($description . self::HREF_NOTE)
            ->withExample($example);
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

        return Schema::create()
            ->withDescription('A JSON:API link. `null` marks a link that does not apply, such as `next` on the last page.')
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

        return Schema::ofType('object')
            ->withProperty('self', $link)
            ->withProperty('related', $link)
            ->withProperty('describedby', $link)
            ->withAdditionalProperties($link);
    }

    /**
     * The top-level `links` of a paginated document that isn't a type's own collection
     * (a to-many relationship document): `self`, `describedby` and the pagination links,
     * all built by the server.
     */
    private function paginationLinksSchema(): Schema
    {
        $link = Schema::ref('#/components/schemas/' . ($this->objects ? 'RenderedLink' : 'LinkObject'));

        return Schema::ofType('object')
            ->withProperty('self', $link)
            ->withProperty('describedby', $link)
            ->withProperty('first', $link)
            ->withProperty('prev', $link)
            ->withProperty('next', $link)
            ->withProperty('last', $link);
    }
}
