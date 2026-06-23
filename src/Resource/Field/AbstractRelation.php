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
 * surface (`inverseType()`, `cannotEagerLoad()`, the URI helpers) plus the
 * serialize/hydrate routing relationships use. The related resource type(s) are a
 * mandatory factory argument (see {@see DeclaresMonomorphicType} /
 * {@see DeclaresPolymorphicTypes}), not part of the fluent surface.
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

    /**
     * Whether this relation emits its linkage `data` (its resource identifier(s),
     * distinct from the `self`/`related` links) only when the related value is
     * already loaded/included — emitting links-only otherwise, never forcing a
     * storage load just to render identifiers.
     *
     * The default is **per relation type**, keyed on whether resolving the linkage
     * is free (the identifier is on the owning side): `false` (eager — data always
     * rendered, no query) for {@see BelongsTo} and {@see MorphTo} (the FK / morph-id
     * sits on the owner); `true` (lazy — links-only until loaded/included) for the
     * to-many relations and {@see HasOne} (their identifier is on the *related* side,
     * so resolving it is a query). Override a lazy relation to eager with
     * {@see withData()}. Eager is always safe for an owner-side to-one; the lazy
     * default exists to avoid an N+1 across a collection.
     */
    protected bool $dataOnlyWhenLoaded = true;

    protected bool $allowsReplace = true;

    protected bool $allowsRemove = true;

    protected bool $exposesRelatedEndpoint = true;

    protected bool $exposesRelationshipEndpoint = true;

    protected bool $allowsAdd = true;

    protected bool $isIncludable = true;

    /**
     * Model + request predicate prohibiting replacement: when set, replacement is
     * prohibited **for this request** iff the closure returns `true`. Independent
     * of {@see $allowsReplace} (the unconditional flag).
     *
     * @var \Closure(mixed, JsonApiRequestInterface): bool|null
     */
    protected ?\Closure $cannotReplaceWhen = null;

    /**
     * Model + request predicate prohibiting removal.
     *
     * @var \Closure(mixed, JsonApiRequestInterface): bool|null
     */
    protected ?\Closure $cannotRemoveWhen = null;

    /**
     * Model + request predicate prohibiting addition.
     *
     * @var \Closure(mixed, JsonApiRequestInterface): bool|null
     */
    protected ?\Closure $cannotAddWhen = null;

    /**
     * Model + request predicate prohibiting inclusion.
     *
     * @var \Closure(mixed, JsonApiRequestInterface): bool|null
     */
    protected ?\Closure $cannotBeIncludedWhen = null;

    protected bool $isCountable = false;

    /**
     * The per-relation resolver that contributes `meta` to each linkage identifier
     * object this relation renders, set by {@see identifierMeta()}. Parent-aware:
     * unlike the related resource's own {@see SerializerInterface::getMeta()} (which
     * sees only the related object), this receives the owning model too, so the meta
     * can describe the *link* (e.g. when the relationship was established). `null`
     * when the relation declares none.
     *
     * @var (\Closure(mixed $parent, mixed $related, JsonApiRequestInterface $request): array<string, mixed>)|null
     */
    protected ?\Closure $identifierMetaResolver = null;

    protected ?\haddowg\JsonApi\Pagination\PaginatorInterface $relationPaginator = null;

    /**
     * Whether this relation explicitly opts out of pagination (fetch-all), set by
     * {@see withoutPagination()}. When `true`, {@see pagination()} returns `null`
     * regardless of the resolved fallback. Off by default.
     */
    private bool $paginationDisabled = false;

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
     * Records the related resource type(s) for this relation. Internal: the
     * type is supplied once, as the mandatory factory argument
     * ({@see DeclaresMonomorphicType::make()} / {@see DeclaresPolymorphicTypes::make()}),
     * never reset fluently — a relationship is meaningless without a type, so
     * there is no public setter to omit it.
     *
     * @return static
     */
    protected function withRelatedTypes(string ...$types): static
    {
        $types = \array_values(\array_unique($types));

        if ($types === [] || \in_array('', $types, true)) {
            throw new \InvalidArgumentException(\sprintf(
                'Relationship "%s" must declare at least one non-empty related resource type.',
                $this->name,
            ));
        }

        $this->relatedTypes = $types;

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
     * Opts this relation into **eager** linkage: always render the relationship
     * object's `data` member (its resource identifier(s)), even when the related
     * value is not already loaded. Use it to override the lazy default on a to-many
     * relation or a {@see HasOne} when rendering identifiers is acceptable (or the
     * value is reliably preloaded). It is the inverse of the lazy default; an
     * owner-side to-one ({@see BelongsTo} / {@see MorphTo}) is eager already, so
     * calling this on one is a harmless no-op.
     *
     * Here `data` is the relationship's linkage (its resource identifier(s)),
     * distinct from the relationship's `self`/`related` links. The lazy default it
     * overrides is gated by the injected
     * {@see \haddowg\JsonApi\Serializer\RelationshipLoadStateInterface}: a lazy
     * relation that *is* loaded or included still emits data, and a relation that
     * would render no links and no meta always emits data (never an empty
     * relationship object — see {@see AbstractRelationship::transform()}).
     *
     * @return static
     */
    public function withData(): static
    {
        $this->dataOnlyWhenLoaded = false;

        return $this;
    }

    /**
     * Prohibits full replacement of this relationship: a `PATCH` to its
     * relationship endpoint (and a to-one clear via `data: null`, which is a
     * removal) is rejected with {@see \haddowg\JsonApi\Exception\FullReplacementProhibited}.
     * Both replace and remove are allowed by default.
     *
     * Pass a closure to make the decision request-aware (replacement prohibited
     * **for this request** iff the closure returns `true`, receiving the domain model
     * and the request) — lightweight per-caller authorization. A request-aware
     * prohibition is not *unconditional*, so the superset OpenAPI still exposes the
     * verb.
     *
     * @param \Closure(mixed, JsonApiRequestInterface): bool|null $when
     * @return static
     */
    public function cannotReplace(?\Closure $when = null): static
    {
        if ($when === null) {
            $this->allowsReplace = false;

            return $this;
        }

        $this->cannotReplaceWhen = $when;

        return $this;
    }

    /**
     * Prohibits removal from this relationship: a `DELETE` to its (to-many)
     * relationship endpoint, or clearing a to-one (`data: null`), is rejected with
     * {@see \haddowg\JsonApi\Exception\RemovalProhibited}. Both replace and remove
     * are allowed by default. Pass a closure to gate the prohibition on the domain
     * model and the request (see {@see cannotReplace()}).
     *
     * @param \Closure(mixed, JsonApiRequestInterface): bool|null $when
     * @return static
     */
    public function cannotRemove(?\Closure $when = null): static
    {
        if ($when === null) {
            $this->allowsRemove = false;

            return $this;
        }

        $this->cannotRemoveWhen = $when;

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
     * allowed by default, completing the replace / add / remove gate trio. Pass a
     * closure to gate the prohibition on the domain model and the request (see
     * {@see cannotReplace()}).
     *
     * @param \Closure(mixed, JsonApiRequestInterface): bool|null $when
     * @return static
     */
    public function cannotAdd(?\Closure $when = null): static
    {
        if ($when === null) {
            $this->allowsAdd = false;

            return $this;
        }

        $this->cannotAddWhen = $when;

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
     * Pass a closure to make the decision request-aware (inclusion prohibited
     * **for this request** iff the closure returns `true`, receiving the domain model
     * and the request). A request-aware prohibition is not *unconditional*, so
     * the superset OpenAPI still lists the relation among the includable paths.
     *
     * @param \Closure(mixed, JsonApiRequestInterface): bool|null $when
     * @return static
     */
    public function cannotBeIncluded(?\Closure $when = null): static
    {
        if ($when === null) {
            $this->isIncludable = false;

            return $this;
        }

        $this->cannotBeIncludedWhen = $when;

        return $this;
    }

    /**
     * Declares this (to-many) relation **countable**: its cardinality is exposed
     * as `meta.total` on the relationship object when the request names it in
     * `?withCount`, and its related-collection endpoint (`GET /{type}/{id}/{rel}`)
     * emits the pagination `total` + `last` link. A non-countable relation's
     * endpoint paginates count-free (no `total`, no `last` — "there is a next
     * page" is signalled by `next` alone). The count is the single universal gate:
     * a `?withCount` naming a relation that is not countable (or a to-one) is
     * rejected. Off by default. {@see countable()} is the single universal count
     * gate.
     *
     * @return static
     */
    public function countable(): static
    {
        $this->isCountable = true;

        return $this;
    }

    /**
     * Declares per-relation `meta` for the **resource identifier objects** this
     * relation renders in its linkage — the `{type, id, meta}` form that appears
     * under a relationship's `data`, on every member of a to-many and on a to-one's
     * single identifier (and at the `/relationships/{name}` endpoint).
     *
     * The resolver is parent-aware: it receives the owning `$parent` model, the
     * `$related` object the identifier points at, and the request, and returns the
     * meta to attach. This is what distinguishes it from the related resource's own
     * {@see SerializerInterface::getMeta()} — that meta describes the resource and
     * is identical wherever the resource appears, whereas this describes the *link*
     * from this parent (e.g. the role a member plays, or when the association was
     * formed) and so can only be expressed here, on the owning relation.
     *
     * The returned meta is merged onto whatever the identifier already carries (the
     * related resource's own meta, including a `belongsToMany` pivot's `meta.pivot`),
     * with this resolver winning on a top-level key collision. Returning `[]` emits
     * no `meta` member. It does not affect the related resource object rendered into
     * `included` — only the identifier in the linkage.
     *
     * @param \Closure(mixed $parent, mixed $related, JsonApiRequestInterface $request): array<string, mixed> $resolver
     *
     * @return static
     */
    public function identifierMeta(\Closure $resolver): static
    {
        $this->identifierMetaResolver = $resolver;

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
     * Explicitly opts this relation's related-collection endpoint out of pagination
     * (fetch-all): {@see pagination()} then returns `null` regardless of the
     * resolved fallback, so the whole collection is fetched and rendered with
     * `meta.total` unconditionally (no `page` meta). The level-explicit counterpart
     * of a `null`-returning resource `pagination()`. Fluent: returns `$this`.
     *
     * @return static
     */
    public function withoutPagination(): static
    {
        $this->paginationDisabled = true;

        return $this;
    }

    /**
     * The effective paginator for this relation's related-collection endpoint
     * (`GET /{type}/{id}/{rel}`). When {@see withoutPagination()} disabled it,
     * returns `null` (fetch-all) regardless of `$fallback` — the opt-out
     * short-circuits *before* the fallback so the fallback can never override it.
     * Otherwise returns this relation's own paginator ({@see paginate()}) when set,
     * else `$fallback` (the already-resolved related-resource-or-server default).
     * The chain stays *relation → related resource → server default*, but every
     * level can now also say "no pagination". A to-one relation has no collection
     * and ignores this.
     */
    public function pagination(?\haddowg\JsonApi\Pagination\PaginatorInterface $fallback): ?\haddowg\JsonApi\Pagination\PaginatorInterface
    {
        return $this->paginationDisabled ? null : ($this->relationPaginator ?? $fallback);
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

    public function allowsReplaceFor(JsonApiRequestInterface $request, mixed $model): bool
    {
        return $this->resolveAllows($this->allowsReplace, $this->cannotReplaceWhen, $request, $model);
    }

    public function allowsRemove(): bool
    {
        return $this->allowsRemove;
    }

    public function allowsRemoveFor(JsonApiRequestInterface $request, mixed $model): bool
    {
        return $this->resolveAllows($this->allowsRemove, $this->cannotRemoveWhen, $request, $model);
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

    public function allowsAddFor(JsonApiRequestInterface $request, mixed $model): bool
    {
        return $this->resolveAllows($this->allowsAdd, $this->cannotAddWhen, $request, $model);
    }

    public function isIncludable(): bool
    {
        return $this->isIncludable;
    }

    public function isIncludableFor(JsonApiRequestInterface $request, mixed $model): bool
    {
        return $this->resolveAllows($this->isIncludable, $this->cannotBeIncludedWhen, $request, $model);
    }

    /**
     * Resolves a `cannotX` gate for this request: an unconditional prohibition
     * (`$allows === false`) always denies; otherwise the request predicate (if
     * declared) denies when it returns `true` ("restricted when predicate true").
     * Returns whether the operation is *allowed*.
     *
     * @param \Closure(mixed, JsonApiRequestInterface): bool|null $predicate
     */
    private function resolveAllows(bool $allows, ?\Closure $predicate, JsonApiRequestInterface $request, mixed $model): bool
    {
        if ($allows === false) {
            return false;
        }

        return $predicate === null || !$predicate($model, $request);
    }

    public function isCountable(): bool
    {
        return $this->isCountable;
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

    /**
     * Whether this relation emits its linkage `data` (its resource identifier(s),
     * distinct from the `self`/`related` links) only when the related value is
     * already loaded — the per-type default ({@see $dataOnlyWhenLoaded}), which
     * {@see withData()} overrides to eager. Read by the load-state seam.
     */
    public function emitsDataOnlyWhenLoaded(): bool
    {
        return $this->dataOnlyWhenLoaded;
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
     * When this relation is lazy (the per-type default, not overridden by
     * {@see withData()}) and the injected load-state predicate reports the related
     * value is *not* loaded,
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

        $this->finalizeToOne($relationship, $model, $request);

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

            // Out-of-band linkage: a host (the Relationship Queries profile's window)
            // may supply this relation's page-1 linkage data per (parent, relation) so
            // it need not write that page back onto the parent's backing property — a
            // write that would corrupt any SIBLING relation sharing the column. When a
            // page is supplied it is used eagerly (always emitting a `data` member),
            // never deferred; the parent property is left untouched for its bystanders.
            $linkage = $this->relationshipLinkage($model, $request, $resolver);
            if ($linkage !== null) {
                $relationship->setData($linkage->data, $serializer);
            } elseif ($this->shouldDeferLinkage($model, $resolver)) {
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

        $this->finalizeToMany($relationship, $model, $request, $resolver);

        return $relationship;
    }

    /**
     * Applies the convention `self`/`related` links to a relationship when this
     * relation emits links — the shared link tail of every relationship builder.
     */
    private function applyConventionLinks(AbstractRelationship $relationship): void
    {
        if ($this->includesLinks) {
            $relationship->withConventionLinks(
                $this->uriFieldName(),
                $this->exposesRelationshipEndpoint,
                $this->exposesRelatedEndpoint,
            );
        }
    }

    /**
     * Finalizes a to-one relationship: convention links + the per-(parent, relation)
     * identifier meta. The shared tail of {@see buildToOne()} and
     * {@see \haddowg\JsonApi\Resource\Field\MorphTo}.
     */
    protected function finalizeToOne(AbstractRelationship $relationship, mixed $model, JsonApiRequestInterface $request): void
    {
        $this->applyConventionLinks($relationship);
        $this->applyIdentifierMeta($relationship, $model, $request);
    }

    /**
     * Finalizes a to-many relationship: convention links, the relationship-meta hook
     * (e.g. the countable `meta.total`), the per-(parent, relation) identifier meta,
     * and the pagination links. The shared tail of {@see buildToMany()} and
     * {@see \haddowg\JsonApi\Resource\Field\MorphToMany} — applied in one order so a
     * fifth relation type cannot reintroduce an ordering drift.
     */
    protected function finalizeToMany(
        AbstractRelationship $relationship,
        mixed $model,
        JsonApiRequestInterface $request,
        SerializerResolverInterface $resolver,
    ): void {
        $this->applyConventionLinks($relationship);

        $meta = $this->relationshipMeta($model, $request, $resolver);
        if ($meta !== []) {
            $relationship->setMeta([...$relationship->getMeta(), ...$meta]);
        }

        $this->applyIdentifierMeta($relationship, $model, $request);

        $pagination = $this->resolvePagination($model, $request, $resolver);
        if ($pagination !== null) {
            $relationship->withPagination($pagination);
        }
    }

    /**
     * Resolves the page-1 pagination state for this to-many relation on `$model`
     * under the Relationship Queries profile — the relationship-object
     * `first` / `prev` / `next` (+ `last`) links — or `null` when none should be
     * emitted: no
     * {@see \haddowg\JsonApi\Serializer\RelationshipPaginationInterface} was
     * injected, or the resolver returned `null` (the relation is not paginated for
     * this request). The injected resolver owns the page-1 windowing and the
     * plain-form link translation; core only attaches the result.
     */
    protected function resolvePagination(
        mixed $model,
        JsonApiRequestInterface $request,
        \haddowg\JsonApi\Resource\SerializerResolverInterface $resolver,
    ): ?\haddowg\JsonApi\Schema\Relationship\RelationshipPagination {
        return $resolver->relationshipPagination()?->paginateRelationship($model, $this, $request);
    }

    /**
     * Resolves the out-of-band linkage `data` for this to-many relation on `$model`
     * under the Relationship Queries profile — the windowed page a host supplies per
     * (parent, relation) so the page need not be written back onto the parent's
     * backing property (which would corrupt a sibling relation sharing the column) —
     * or `null` when none is supplied: no
     * {@see \haddowg\JsonApi\Serializer\RelationshipLinkageInterface} was injected, or
     * the resolver returned `null` for this (parent, relation), in which case linkage
     * is read off the model as before. The injected resolver owns the windowing; core
     * only reads the supplied page back.
     */
    protected function relationshipLinkage(
        mixed $model,
        JsonApiRequestInterface $request,
        \haddowg\JsonApi\Resource\SerializerResolverInterface $resolver,
    ): ?\haddowg\JsonApi\Schema\Relationship\RelationshipLinkage {
        return $resolver->relationshipLinkage()?->linkageForRelationship($model, $this, $request);
    }

    /**
     * The relationship-object `meta` this relation contributes for `$model` — the
     * general per-relationship meta-contribution hook merged onto the built
     * relationship by {@see buildToMany()}. Its first consumer is the countable
     * relation `meta.total`: when the relation is {@see countable()}, the request
     * names it in `?withCount` ({@see JsonApiRequestInterface::countsRelationship()}),
     * and an injected
     * {@see \haddowg\JsonApi\Serializer\RelationshipCountInterface} supplies a
     * non-null cardinality, this returns `['total' => N]` (the same `total` key the
     * count-based pages emit, so the relationship-object total and the endpoint
     * pagination total are one consistent semantic). With no resolver injected, a
     * non-countable relation, or a relation the request did not name, this returns
     * `[]` and no meta is emitted. Override (calling `parent`) to contribute further
     * relationship meta.
     *
     * @return array<string, mixed>
     */
    protected function relationshipMeta(
        mixed $model,
        JsonApiRequestInterface $request,
        \haddowg\JsonApi\Resource\SerializerResolverInterface $resolver,
    ): array {
        $meta = [];

        $total = $this->resolveCount($model, $request, $resolver);
        if ($total !== null) {
            $meta['total'] = $total;
        }

        return $meta;
    }

    /**
     * Attaches this relation's {@see identifierMeta()} resolver (if any) onto a
     * freshly built relationship, bound to the owning `$model` and `$request`, so
     * every resource identifier the relationship renders in its linkage is
     * augmented with the parent-aware meta. A no-op when no resolver was declared.
     * Called from each build path ({@see buildToOne()}, {@see buildToMany()}, and
     * the polymorphic builders) — mirroring how {@see relationshipMeta()} is wired
     * at each site — so it applies uniformly across every relation type.
     */
    protected function applyIdentifierMeta(
        AbstractRelationship $relationship,
        mixed $model,
        JsonApiRequestInterface $request,
    ): void {
        $resolver = $this->identifierMetaResolver;
        if ($resolver === null) {
            return;
        }

        $relationship->withIdentifierMeta(
            static fn(mixed $related): array => $resolver($model, $related, $request),
        );
    }

    /**
     * Resolves the countable cardinality for this relation on `$model`, or `null`
     * when no count should be emitted: the relation is not {@see countable()}, the
     * request did not name it in `?withCount`, no
     * {@see \haddowg\JsonApi\Serializer\RelationshipCountInterface} was injected, or
     * the resolver itself returned `null` (no count available for this parent).
     */
    private function resolveCount(
        mixed $model,
        JsonApiRequestInterface $request,
        \haddowg\JsonApi\Resource\SerializerResolverInterface $resolver,
    ): ?int {
        if ($this->isCountable === false || $request->countsRelationship($this->name) === false) {
            return null;
        }

        return $resolver->relationshipCount()?->countRelationship($model, $this);
    }

    /**
     * Whether the linkage data read for this relation should be deferred and the
     * data member omitted-unless-included, per the load-aware policy. True only
     * when the relation is lazy (the per-type default, not overridden by
     * {@see withData()}), it carries the convention links (so omitting data never
     * yields an empty relationship object — the validity guard), an injected
     * {@see \haddowg\JsonApi\Serializer\RelationshipLoadStateInterface} is
     * present, and that predicate reports the related value is *not* loaded.
     * With no predicate injected the relation is treated as loaded (standalone
     * default).
     */
    protected function shouldDeferLinkage(
        mixed $model,
        \haddowg\JsonApi\Resource\SerializerResolverInterface $resolver,
    ): bool {
        if ($this->dataOnlyWhenLoaded === false || $this->includesLinks === false) {
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
