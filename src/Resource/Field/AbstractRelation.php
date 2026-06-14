<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship as InputToMany;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship as InputToOne;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Constraint\RelationshipType;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Schema\Relationship\AbstractRelationship;
use haddowg\JsonApi\Schema\Relationship\ToManyRelationship as OutputToMany;
use haddowg\JsonApi\Schema\Relationship\ToOneRelationship as OutputToOne;
use haddowg\JsonApi\Serializer\SerializerInterface;

/**
 * Convenience base for {@see RelationInterface} fields. Reuses {@see AbstractField}'s
 * flag / constraint / context machinery and adds the relationship-shaping fluent
 * surface (`type()`, `inverseType()`, `cannotEagerLoad()`, the URI helpers) plus
 * the serialize/hydrate routing relationships use.
 *
 * The attribute serialize/hydrate paths are not used for relations — the schema
 * routes through {@see buildRelationship()} / {@see hydrateRelationship()} — so
 * the inherited {@see serialize()} returns the related value extraction and
 * {@see hydrate()} is a no-op (relationship hydration runs via the request's
 * parsed linkage, not a raw attribute value).
 */
abstract class AbstractRelation extends AbstractField implements \haddowg\JsonApi\Resource\Field\RelationInterface
{
    /**
     * @var list<string>
     */
    protected array $relatedTypes = [];

    protected ?string $inverseType = null;

    protected bool $eagerLoad = true;

    protected ?string $uriFieldName = null;

    protected bool $includesLinks = true;

    protected bool $linkageOnlyWhenLoaded = false;

    protected bool $allowsReplace = true;

    protected bool $allowsRemove = true;

    protected bool $exposesRelatedEndpoint = true;

    protected bool $exposesRelationshipEndpoint = true;

    protected bool $allowsAdd = true;

    protected bool $isIncludable = true;

    protected ?\haddowg\JsonApi\Pagination\PaginatorInterface $relationPaginator = null;

    /**
     * Extra filters scoped to this relation's related-collection endpoint.
     *
     * @var list<\haddowg\JsonApi\Resource\Filter\FilterInterface>
     */
    protected array $relationFilters = [];

    /**
     * Extra sorts scoped to this relation's related-collection endpoint.
     *
     * @var list<\haddowg\JsonApi\Resource\Sort\SortInterface>
     */
    protected array $relationSorts = [];

    /**
     * Declares the related resource type(s). A single type for a monomorphic
     * relation; pass several (or call repeatedly) for a polymorphic one.
     *
     * @return static
     */
    public function type(string ...$types): static
    {
        $this->relatedTypes = \array_values(\array_unique([...$this->relatedTypes, ...$types]));

        return $this;
    }

    /**
     * Records the inverse relationship name on the related type (advisory
     * metadata for adapters / OpenAPI generation).
     *
     * @return static
     */
    public function inverseType(string $inverseType): static
    {
        $this->inverseType = $inverseType;

        return $this;
    }

    /**
     * @return static
     */
    public function cannotEagerLoad(): static
    {
        $this->eagerLoad = false;

        return $this;
    }

    /**
     * Overrides the URI segment used for this relationship (defaults to the
     * field name).
     *
     * @return static
     */
    public function withUriFieldName(string $uriFieldName): static
    {
        $this->uriFieldName = $uriFieldName;

        return $this;
    }

    /**
     * Suppresses the conventional `self` / `related` relationship links this
     * relation otherwise emits by default.
     *
     * @return static
     */
    public function withoutLinks(): static
    {
        $this->includesLinks = false;

        return $this;
    }

    /**
     * Opts this relation into load-aware linkage: when the related value is not
     * already loaded, emit the relationship object's `links` only and omit the
     * `data` member rather than triggering a (lazy) storage load just to render
     * identifiers. Off by default. Gated by the injected
     * {@see \haddowg\JsonApi\Serializer\RelationshipLoadStateInterface}: an
     * included relationship still emits data (include-wins), and a relation with
     * no links always emits data (never an empty relationship object).
     *
     * @return static
     */
    public function linkageOnlyWhenLoaded(): static
    {
        $this->linkageOnlyWhenLoaded = true;

        return $this;
    }

    /**
     * Prohibits full replacement of this relationship: a `PATCH` to its
     * relationship endpoint (and a to-one clear via `data: null`, which is a
     * removal) is rejected with {@see \haddowg\JsonApi\Exception\FullReplacementProhibited}.
     * Both replace and remove are allowed by default.
     *
     * @return static
     */
    public function cannotReplace(): static
    {
        $this->allowsReplace = false;

        return $this;
    }

    /**
     * Prohibits removal from this relationship: a `DELETE` to its (to-many)
     * relationship endpoint, or clearing a to-one (`data: null`), is rejected with
     * {@see \haddowg\JsonApi\Exception\RemovalProhibited}. Both replace and remove
     * are allowed by default.
     *
     * @return static
     */
    public function cannotRemove(): static
    {
        $this->allowsRemove = false;

        return $this;
    }

    /**
     * Suppresses this relation's related HTTP endpoint (`GET /{type}/{id}/{rel}`):
     * the host treats a request for it as a 404, and the conventional `related`
     * link is omitted so a rendered link never points at that 404. The endpoint is
     * exposed by default.
     *
     * @return static
     */
    public function withoutRelatedEndpoint(): static
    {
        $this->exposesRelatedEndpoint = false;

        return $this;
    }

    /**
     * Suppresses this relation's relationship-linkage HTTP endpoint
     * (`GET|PATCH|POST|DELETE /{type}/{id}/relationships/{rel}`): the host treats a
     * request for it as a 404, and the conventional `self` link is omitted so a
     * rendered link never points at that 404. The endpoint is exposed by default.
     *
     * @return static
     */
    public function withoutRelationshipEndpoint(): static
    {
        $this->exposesRelationshipEndpoint = false;

        return $this;
    }

    /**
     * Prohibits additions to this (to-many) relationship: a `POST` to its
     * relationship endpoint is rejected with
     * {@see \haddowg\JsonApi\Exception\AdditionProhibited} (403). Additions are
     * allowed by default, completing the replace / add / remove gate trio.
     *
     * @return static
     */
    public function cannotAdd(): static
    {
        $this->allowsAdd = false;

        return $this;
    }

    /**
     * Prohibits this relationship from being included in a compound document: a
     * `?include` naming it (at any path) is rejected with
     * {@see \haddowg\JsonApi\Exception\InclusionNotAllowed} (400), and it is
     * excluded from the default-include cascade. The relationship linkage and its
     * `self` / `related` links are unaffected — only the compound `included`
     * expansion is suppressed. Includable by default.
     *
     * @return static
     */
    public function cannotBeIncluded(): static
    {
        $this->isIncludable = false;

        return $this;
    }

    /**
     * Sets the default paginator for this relation's related-collection endpoint
     * (`GET /{type}/{id}/{rel}`). A to-many relation paginates its related
     * collection with this strategy when the request carries `page[…]`; a to-one
     * relation has no collection and ignores it. Mutates and returns `$this`,
     * matching the relation builder's other fluent setters.
     *
     * @return static
     */
    public function paginate(\haddowg\JsonApi\Pagination\PaginatorInterface $paginator): static
    {
        $this->relationPaginator = $paginator;

        return $this;
    }

    /**
     * This relation's declared default paginator, or `null` for none. It is the
     * to-many related-endpoint paginator (a to-one relation ignores it); the host
     * resolves the effective strategy as relation → related-resource → server
     * default.
     */
    public function pagination(): ?\haddowg\JsonApi\Pagination\PaginatorInterface
    {
        return $this->relationPaginator;
    }

    /**
     * Declares extra filters scoped to this relation's related-collection endpoint
     * (`GET /{type}/{id}/{rel}`) — not the primary collection of the related type.
     * Appends to any already declared, matching the relation builder's other
     * fluent setters. Read them back with {@see filters()}. The host merges them
     * with the related resource's own filters; on a key clash the relation's
     * declaration wins (the more specific scope).
     *
     * @return static
     */
    public function withFilters(\haddowg\JsonApi\Resource\Filter\FilterInterface ...$filters): static
    {
        $this->relationFilters = [...$this->relationFilters, ...\array_values($filters)];

        return $this;
    }

    /**
     * Declares extra sorts scoped to this relation's related-collection endpoint
     * (`GET /{type}/{id}/{rel}`) — not the primary collection of the related type.
     * Appends to any already declared, matching the relation builder's other
     * fluent setters. Read them back with {@see sorts()}. The host merges them with
     * the related resource's own sorts; on a key clash the relation's declaration
     * wins (the more specific scope).
     *
     * @return static
     */
    public function withSorts(\haddowg\JsonApi\Resource\Sort\SortInterface ...$sorts): static
    {
        $this->relationSorts = [...$this->relationSorts, ...\array_values($sorts)];

        return $this;
    }

    public function filters(): array
    {
        return $this->relationFilters;
    }

    public function sorts(): array
    {
        return $this->relationSorts;
    }

    public function allowsReplace(): bool
    {
        return $this->allowsReplace;
    }

    public function allowsRemove(): bool
    {
        return $this->allowsRemove;
    }

    public function exposesRelatedEndpoint(): bool
    {
        return $this->exposesRelatedEndpoint;
    }

    public function exposesRelationshipEndpoint(): bool
    {
        return $this->exposesRelationshipEndpoint;
    }

    public function allowsAdd(): bool
    {
        return $this->allowsAdd;
    }

    public function isIncludable(): bool
    {
        return $this->isIncludable;
    }

    public function relatedTypes(): array
    {
        return $this->relatedTypes;
    }

    public function canEagerLoad(): bool
    {
        return $this->eagerLoad;
    }

    public function includesLinks(): bool
    {
        return $this->includesLinks;
    }

    public function emitsLinkageOnlyWhenLoaded(): bool
    {
        return $this->linkageOnlyWhenLoaded;
    }

    public function resolveSerializer(mixed $related, SerializerResolverInterface $resolver): ?SerializerInterface
    {
        $monomorphic = \count($this->relatedTypes) === 1;

        foreach ($this->relatedTypes as $type) {
            if (!$resolver->hasSerializerFor($type)) {
                continue;
            }

            $serializer = $resolver->serializerFor($type);
            if ($related === null || $monomorphic || $serializer->getType($related) === $type) {
                return $serializer;
            }
        }

        return null;
    }

    /**
     * The URI segment for this relationship.
     */
    public function uriFieldName(): string
    {
        return $this->uriFieldName ?? $this->name;
    }

    public function constraints(): array
    {
        $constraints = parent::constraints();

        if ($this->relatedTypes !== []) {
            $constraints[] = new RelationshipType($this->relatedTypes);
        }

        return $constraints;
    }

    public function serialize(mixed $model, JsonApiRequestInterface $request, string $name): mixed
    {
        return $this->relatedValue($model, $request, $name);
    }

    public function hydrate(mixed $model, mixed $value, array $data, JsonApiRequestInterface $request, bool $creating): mixed
    {
        return $model;
    }

    public function readValue(mixed $model, JsonApiRequestInterface $request): mixed
    {
        return $this->relatedValue($model, $request, $this->name);
    }

    public function hydrateRelationship(mixed $model, object $relationship): mixed
    {
        if ($this->fillUsing !== null) {
            $result = ($this->fillUsing)($model, $relationship, [], $this->name);

            return $result ?? $model;
        }

        return $this->applyRelationship($model, $relationship);
    }

    /**
     * Resolves the related domain value(s) from the parent model — a single
     * object for a to-one relation, an iterable for a to-many one.
     */
    protected function relatedValue(mixed $model, JsonApiRequestInterface $request, string $name): mixed
    {
        if ($this->extractUsing !== null) {
            return ($this->extractUsing)($model, $request, $name);
        }

        return Accessor::get($model, $this->column ?? $name);
    }

    public function applyToMany(mixed $model, object $relationship, Mode $mode): mixed
    {
        if (!$relationship instanceof InputToMany) {
            return $model;
        }

        if ($this->fillUsing !== null) {
            $result = ($this->fillUsing)($model, $relationship, ['mode' => $mode], $this->name);

            return $result ?? $model;
        }

        $column = $this->column;
        if ($column === null) {
            return $model;
        }

        if ($mode === Mode::Replace) {
            return Accessor::set($model, $column, $relationship->getResourceIdentifierIds());
        }

        /** @var list<string> $current */
        $current = \array_values(\array_filter(
            (array) (Accessor::get($model, $column) ?? []),
            static fn(mixed $id): bool => $id !== null,
        ));
        /** @var list<string> $incoming */
        $incoming = \array_values(\array_filter(
            $relationship->getResourceIdentifierIds(),
            static fn(?string $id): bool => $id !== null,
        ));

        if ($mode === Mode::Add) {
            // Append, deduplicating so add is idempotent (set semantics).
            $next = \array_values(\array_unique([...$current, ...$incoming]));

            return Accessor::set($model, $column, $next);
        }

        // Mode::Remove — subtract the incoming ids from the existing set.
        $next = \array_values(\array_filter($current, static fn(string $id): bool => !\in_array($id, $incoming, true)));

        return Accessor::set($model, $column, $next);
    }

    /**
     * Writes the parsed input relationship into the domain object. Default:
     * store the related id(s) on the field's column ({@see Mode::Replace}
     * semantics). Override for richer cardinality handling.
     *
     * @param InputToOne|InputToMany|object $relationship
     */
    protected function applyRelationship(mixed $model, object $relationship): mixed
    {
        $column = $this->column;
        if ($column === null) {
            return $model;
        }

        if ($relationship instanceof InputToOne) {
            return Accessor::set($model, $column, $relationship->resourceIdentifier?->id);
        }

        if ($relationship instanceof InputToMany) {
            return Accessor::set($model, $column, $relationship->getResourceIdentifierIds());
        }

        return $model;
    }

    /**
     * Builds a to-one output relationship for `$model`.
     *
     * When this relation has opted into {@see linkageOnlyWhenLoaded()} and the
     * injected load-state predicate reports the related value is *not* loaded,
     * the linkage data read is deferred behind a callable and the relationship is
     * flagged {@see AbstractRelationship::omitDataWhenNotIncluded()}: the
     * transformer omits the `data` member (emitting links only) unless the
     * relationship is included, in which case the callable runs and the value is
     * read as today (include-wins). Otherwise the value is read eagerly and the
     * data member is set, exactly as before.
     */
    protected function buildToOne(
        mixed $model,
        JsonApiRequestInterface $request,
        \haddowg\JsonApi\Resource\SerializerResolverInterface $resolver,
    ): OutputToOne {
        $relationship = OutputToOne::create();

        $type = $this->relatedTypes[0] ?? null;
        if ($type !== null && $resolver->hasSerializerFor($type)) {
            $serializer = $resolver->serializerFor($type);
            if ($this->shouldDeferLinkage($model, $resolver)) {
                $relationship
                    ->setDataAsCallable(fn(): mixed => $this->relatedValue($model, $request, $this->name), $serializer)
                    ->omitDataWhenNotIncluded();
            } else {
                // Always bind the serializer (even for a null related value) so the
                // relationship carries its resource: an empty to-one then renders
                // `data: null` rather than omitting the data member, which the
                // relationship-linkage endpoint (`/relationships/{name}`) requires
                // per the spec. The data member stays sparse in a full resource
                // document via the transformer's include/current-relationship gate,
                // so omitting it there is unaffected.
                $relationship->setData($this->relatedValue($model, $request, $this->name), $serializer);
            }
        }

        if ($this->includesLinks) {
            $relationship->withConventionLinks(
                $this->uriFieldName(),
                $this->exposesRelationshipEndpoint,
                $this->exposesRelatedEndpoint,
            );
        }

        return $relationship;
    }

    /**
     * Builds a to-many output relationship for `$model`. Linkage is deferred and
     * omitted-unless-included under the same load-aware policy as
     * {@see buildToOne()}.
     */
    protected function buildToMany(
        mixed $model,
        JsonApiRequestInterface $request,
        \haddowg\JsonApi\Resource\SerializerResolverInterface $resolver,
    ): OutputToMany {
        $relationship = OutputToMany::create();

        $type = $this->relatedTypes[0] ?? null;
        if ($type !== null && $resolver->hasSerializerFor($type)) {
            $serializer = $resolver->serializerFor($type);
            if ($this->shouldDeferLinkage($model, $resolver)) {
                $relationship
                    ->setDataAsCallable(fn(): mixed => $this->relatedValue($model, $request, $this->name), $serializer)
                    ->omitDataWhenNotIncluded();
            } else {
                // Always bind the serializer (mirrors buildToOne): a to-many over a
                // null/absent related value then renders `data: []` rather than
                // omitting it, so the relationship-linkage endpoint is spec-correct.
                $relationship->setData($this->relatedValue($model, $request, $this->name), $serializer);
            }
        }

        if ($this->includesLinks) {
            $relationship->withConventionLinks(
                $this->uriFieldName(),
                $this->exposesRelationshipEndpoint,
                $this->exposesRelatedEndpoint,
            );
        }

        return $relationship;
    }

    /**
     * Whether the linkage data read for this relation should be deferred and the
     * data member omitted-unless-included, per the load-aware policy. True only
     * when the relation opted into {@see linkageOnlyWhenLoaded()}, it carries the
     * convention links (so omitting data never yields an empty relationship
     * object — the validity guard), an injected
     * {@see \haddowg\JsonApi\Serializer\RelationshipLoadStateInterface} is
     * present, and that predicate reports the related value is *not* loaded.
     * With no predicate injected the relation is treated as loaded (standalone
     * default).
     */
    protected function shouldDeferLinkage(
        mixed $model,
        \haddowg\JsonApi\Resource\SerializerResolverInterface $resolver,
    ): bool {
        if ($this->linkageOnlyWhenLoaded === false || $this->includesLinks === false) {
            return false;
        }

        $loadState = $resolver->relationshipLoadState();
        if ($loadState === null) {
            return false;
        }

        return $loadState->isRelationshipLoaded($model, $this) === false;
    }

    /**
     * Applies the field's declared eager-load / sparse defaults onto a freshly
     * built relationship. (Currently a hook for future link/meta wiring.)
     */
    abstract public function buildRelationship(
        mixed $model,
        JsonApiRequestInterface $request,
        \haddowg\JsonApi\Resource\SerializerResolverInterface $resolver,
    ): AbstractRelationship;
}
