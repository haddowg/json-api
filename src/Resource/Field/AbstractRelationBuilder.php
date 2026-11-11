<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * The mutable base for a **relation builder**: the relationship-shaping fluent
 * surface an author chains on top of the inherited {@see AbstractFieldBuilder}
 * (read-only / hidden / `fillUsing` / `extractUsing` / the constraint machinery)
 * — `inverseType()`, the URI helpers, the link / linkage policy, the
 * replace / add / remove / include gates, security, pagination, and the
 * relation-scoped filters / sorts. The fluent methods mutate and return `$this`,
 * so a relation is declared in one expression
 * (`BelongsTo::make('author', 'users')->cannotReplace()->withoutLinks()`).
 *
 * {@see build()} snapshots the accumulated relation state into an immutable
 * {@see RelationState} (via {@see relationState()}) alongside the inherited
 * {@see FieldState}, and constructs the concrete readonly
 * {@see AbstractRelationValue} the engine walks. The related resource type(s) are
 * a mandatory factory argument (see {@see DeclaresMonomorphicType} /
 * {@see DeclaresPolymorphicTypes}), not part of the fluent surface.
 *
 * @phpstan-consistent-constructor
 */
abstract class AbstractRelationBuilder extends AbstractFieldBuilder
{
    /**
     * @var list<string>
     */
    protected array $relatedTypes = [];

    protected ?string $inverseType = null;

    protected ?string $uriFieldName = null;

    protected bool $includesLinks = true;

    /**
     * Whether this relation emits its linkage `data` (its resource identifier(s),
     * distinct from the `self`/`related` links) only when the related value is
     * already loaded/included — emitting links-only otherwise, never forcing a
     * storage load just to render identifiers.
     *
     * The default is **per relation type**, keyed on whether resolving the linkage
     * is free (the identifier is on the owning side): `false` (eager) for the
     * owner-side to-ones ({@see BelongsToBuilder} / {@see MorphToBuilder}, which set
     * it in their declaration); `true` (lazy) for the to-many relations and
     * {@see HasOneBuilder} (their identifier is on the *related* side). Override a
     * lazy relation to eager with {@see withData()}.
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

    /**
     * Per-relation security DECLARATIONS for this relation's endpoints, set by
     * {@see security()}. Each is opaque to core — a host authorization expression
     * string, the bool `true`/`false`, or `null` (inherit the owning resource's
     * read/update security). `$securityRead` governs the related + relationship READ
     * endpoints; `$securityMutate` governs relationship MUTATION.
     */
    protected string|bool|null $securityRead = null;

    protected string|bool|null $securityMutate = null;

    protected bool $isCountable = false;

    protected ?string $exampleId = null;

    protected bool $allowsMembershipChange = true;

    /**
     * Model + request predicate prohibiting a change of members.
     *
     * @var \Closure(mixed, JsonApiRequestInterface): bool|null
     */
    protected ?\Closure $cannotChangeMembershipWhen = null;

    /**
     * The per-relation resolver that contributes `meta` to each linkage identifier
     * object this relation renders, set by {@see identifierMeta()}. Parent-aware:
     * it receives the owning model too, so the meta can describe the *link*. `null`
     * when the relation declares none.
     *
     * @var (\Closure(mixed $parent, mixed $related, JsonApiRequestInterface $request): array<string, mixed>)|null
     */
    protected ?\Closure $identifierMetaResolver = null;

    /**
     * The members {@see $identifierMetaResolver} produces, declared with the field DSL so
     * the OpenAPI projection can type them, keyed by field name. Empty when the relation
     * declares none.
     *
     * @var array<string, FieldInterface>
     */
    protected array $identifierMetaFields = [];

    protected ?\haddowg\JsonApi\Pagination\PaginatorInterface $relationPaginator = null;

    /**
     * Whether this relation explicitly opts out of pagination (fetch-all), set by
     * {@see withoutPagination()}. When `true`, the built value object's
     * {@see AbstractRelationValue::pagination()} returns `null` regardless of the
     * resolved fallback. Off by default.
     */
    protected bool $paginationDisabled = false;

    /**
     * Extra filters scoped to this relation's related-collection endpoint. Each
     * entry may be a filter builder or an already-built filter; the value object's
     * {@see AbstractRelationValue::allFilters()} builds any builder before use.
     *
     * @var list<\haddowg\JsonApi\Resource\Filter\FilterInterface|\haddowg\JsonApi\Resource\Filter\FilterBuilderInterface>
     */
    protected array $relationFilters = [];

    /**
     * Whether {@see withoutFilterDefaults()} drops every related-type filter default.
     */
    protected bool $dropsAllFilterDefaults = false;

    /**
     * The filter keys whose related-type default {@see withoutFilterDefaults()} drops.
     *
     * @var list<string>
     */
    protected array $droppedFilterDefaults = [];

    /**
     * Extra sorts scoped to this relation's related-collection endpoint.
     *
     * @var list<\haddowg\JsonApi\Resource\Sort\SortInterface>
     */
    protected array $relationSorts = [];

    /**
     * The members' read order when a request names no sort, set by {@see defaultSort()}.
     *
     * @var list<\haddowg\JsonApi\Resource\Sort\SortDirective>
     */
    protected array $defaultSort = [];

    /**
     * The stored integer field holding each member's position, set by a to-many
     * builder's `positionedBy()`.
     */
    protected ?string $positionField = null;

    /**
     * Freezes the accumulated authoring state into the concrete readonly relation
     * value object the engine consumes. Pure and idempotent.
     */
    abstract public function build(): RelationInterface;

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
     * relationship object).
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
     * Fixes this (to-many) relationship's members: a replacement may reorder them but
     * must list exactly the current members, ignoring order, or it is rejected with
     * {@see \haddowg\JsonApi\Exception\MembershipChangeProhibited} (403). The rule
     * covers a `PATCH` to the relationship endpoint and the relation's linkage in a
     * whole-resource `PATCH`. It also closes the add and remove gates, so a `POST` or
     * `DELETE` to the relationship endpoint gets the {@see cannotAdd()} and
     * {@see cannotRemove()} 403s. Pass a closure to gate the prohibition on the domain
     * model and the request (see {@see cannotReplace()}).
     *
     * A to-one has no member set, so declaring it there fails {@see build()}.
     *
     * @param \Closure(mixed, JsonApiRequestInterface): bool|null $when
     * @return static
     */
    public function cannotChangeMembership(?\Closure $when = null): static
    {
        if ($when === null) {
            $this->allowsMembershipChange = false;

            return $this;
        }

        $this->cannotChangeMembershipWhen = $when;

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
     * Declares per-relation security for this relation's own endpoints, **overriding**
     * the owning resource's read/update security for them (it falls back to the
     * resource's security only for whichever of `$read`/`$mutate` is left `null`). The
     * relationship endpoints are otherwise gated by the *parent* resource's security;
     * this is the seam to authorize a relationship independently — more *or* less
     * permissive than its parent.
     *
     *  - `$read` governs the related and relationship READ endpoints
     *    (`GET /{type}/{id}/{rel}` and `GET /{type}/{id}/relationships/{rel}`).
     *  - `$mutate` governs relationship MUTATION
     *    (`PATCH`/`POST`/`DELETE /{type}/{id}/relationships/{rel}`).
     *
     * Each value is, like the resource's `security`: a host authorization expression
     * **string** (enforced against the parent + documented secured), **`true`**
     * (documented secured only — an external firewall enforces it), **`false`**
     * (documented public), or **`null`** (inherit the resource's read/update). A bool
     * is documentation-only; only a string is enforced, and only when the host's
     * authorization layer is installed.
     *
     * @return static
     */
    public function security(string|bool|null $read = null, string|bool|null $mutate = null): static
    {
        $this->securityRead = $read;
        $this->securityMutate = $mutate;

        return $this;
    }

    /**
     * Declares this (to-many) relation **countable**: its cardinality is exposed
     * as `meta.total` on the relationship object when the request names it in
     * `?withCount`, and its related-collection endpoint (`GET /{type}/{id}/{rel}`)
     * emits the pagination `total` + `last` link. A non-countable relation's
     * endpoint paginates count-free (no `total`, no `last`). The count is the single
     * universal gate: a `?withCount` naming a relation that is not countable (or a
     * to-one) is rejected. Off by default.
     *
     * @return static
     */
    public function countable(): static
    {
        $this->isCountable = true;

        return $this;
    }

    /**
     * Declares the related resource id this relation's linkage shows in generated
     * examples. The OpenAPI projection uses the related type's own `Id` example when it
     * has one, and this where it does not: a related type the server does not register,
     * or one whose `Id` declares no example. Render-neutral, like `example()`.
     *
     * @return static
     */
    public function exampleId(string|int $id): static
    {
        $this->exampleId = (string) $id;

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
     * {@see \haddowg\JsonApi\Serializer\SerializerInterface::getMeta()} — that meta
     * describes the resource and is identical wherever the resource appears, whereas
     * this describes the *link* from this parent and so can only be expressed here,
     * on the owning relation.
     *
     * The returned meta is merged onto whatever the identifier already carries, with
     * this resolver winning on a top-level key collision. Returning `[]` emits no
     * `meta` member. It does not affect the related resource object rendered into
     * `included` — only the identifier in the linkage.
     *
     * `$fields` optionally describes the members the resolver returns, with the same field
     * DSL attributes use (`Str::make('role')`, `Boolean::make('primary')`), so the OpenAPI
     * projection types the identifier's `meta` instead of leaving it open. They describe
     * the shape only: the resolver still produces the values, already in wire form. A
     * member named `pivot` is refused, since that key carries a `belongsToMany` relation's
     * pivot fields.
     *
     * @param \Closure(mixed $parent, mixed $related, JsonApiRequestInterface $request): array<string, mixed> $resolver
     * @param list<FieldInterface|FieldBuilderInterface>                                                       $fields
     *
     * @return static
     */
    public function identifierMeta(\Closure $resolver, array $fields = []): static
    {
        $declared = [];
        foreach ($fields as $field) {
            $built = $field instanceof FieldBuilderInterface ? $field->build() : $field;
            if ($built->name() === 'pivot') {
                throw new \LogicException(\sprintf(
                    'Relation "%s" cannot declare an identifier meta member named "pivot": that key carries a belongsToMany relation\'s pivot fields.',
                    $this->name,
                ));
            }
            $declared[$built->name()] = $built;
        }

        $this->identifierMetaResolver = $resolver;
        $this->identifierMetaFields = $declared;

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
     * (fetch-all): the built value object's
     * {@see AbstractRelationValue::pagination()} then returns `null` regardless of
     * the resolved fallback, so the whole collection is fetched and rendered with
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
     * Declares extra filters scoped to this relation's related-collection endpoint
     * (`GET /{type}/{id}/{rel}`) — not the primary collection of the related type.
     * Appends to any already declared, matching the relation builder's other
     * fluent setters. The host merges them with the related resource's own filters;
     * on a key clash the relation's declaration wins (the more specific scope).
     *
     * @return static
     */
    public function withFilters(\haddowg\JsonApi\Resource\Filter\FilterInterface|\haddowg\JsonApi\Resource\Filter\FilterBuilderInterface ...$filters): static
    {
        $this->relationFilters = [...$this->relationFilters, ...\array_values($filters)];

        return $this;
    }

    /**
     * Stops the related type's filter defaults applying to this relation's reads: its
     * linkage, `?include`, `?withCount`, its related and relationship endpoints and a
     * Relationship Queries window. With no keys every default the related type declares
     * stops applying; with keys only those keys' defaults do. A filter the client sends
     * still applies, and the related type's own collection keeps its defaults. A default
     * the relation declares itself, through {@see withFilters()}, is unaffected.
     *
     *     HasMany::make('tracks', 'tracks')->withoutFilterDefaults('explicit');
     *
     * Each key must name a filter of the related type that declares a default; the
     * check needs the related type, so {@see \haddowg\JsonApi\Serializer\RelationFilterDefaultsValidator}
     * runs it when the host warms its resources. Appends to any keys already named.
     *
     * @return static
     */
    public function withoutFilterDefaults(string ...$keys): static
    {
        if ($keys === []) {
            $this->dropsAllFilterDefaults = true;

            return $this;
        }

        foreach ($keys as $key) {
            if ($key === '') {
                throw new \InvalidArgumentException(\sprintf('Relation "%s" names an empty filter key in withoutFilterDefaults().', $this->name));
            }
        }

        $this->droppedFilterDefaults = \array_values(\array_unique([...$this->droppedFilterDefaults, ...\array_values($keys)]));

        return $this;
    }

    /**
     * Declares extra sorts scoped to this relation's related-collection endpoint
     * (`GET /{type}/{id}/{rel}`) — not the primary collection of the related type.
     * Appends to any already declared, matching the relation builder's other
     * fluent setters. The host merges them with the related resource's own sorts;
     * on a key clash the relation's declaration wins (the more specific scope).
     *
     * @return static
     */
    public function withSorts(\haddowg\JsonApi\Resource\Sort\SortInterface ...$sorts): static
    {
        $this->relationSorts = [...$this->relationSorts, ...\array_values($sorts)];

        return $this;
    }

    /**
     * Declares the order this (to-many) relation's members are read in when the
     * request names no `sort`: in its document linkage, on its relationship endpoint
     * (`GET /{type}/{id}/relationships/{rel}`) and on its related endpoint
     * (`GET /{type}/{id}/{rel}`). The syntax and semantics are those of
     * {@see \haddowg\JsonApi\Resource\AbstractResource::defaultSort()}: each
     * {@see \haddowg\JsonApi\Resource\Sort\SortDirective} pairs a sort with its
     * direction, most significant first, and a requested `sort` replaces the whole
     * default. For this relation it replaces the related type's own default.
     *
     *     HasMany::make('tracks', 'tracks')
     *         ->defaultSort(new SortDirective(SortByField::make('position'), descending: false));
     *
     * A relation positioned with `positionedBy()` reads in position order unless it
     * declares a default of its own.
     *
     * Each directive's key must name a sort the relation accepts: one of the related
     * type's sorts, a {@see withSorts()} sort or a pivot field. A key a polymorphic
     * relation or a declared vocabulary can't answer fails the build; one that needs
     * the related type is checked by {@see \haddowg\JsonApi\Serializer\RelationOrderValidator}
     * when the host warms its resources. Declaring a default on a to-one fails the
     * build. Replaces any earlier default.
     *
     * @return static
     */
    public function defaultSort(\haddowg\JsonApi\Resource\Sort\SortDirective ...$directives): static
    {
        $this->defaultSort = \array_values($directives);

        return $this;
    }

    /**
     * Records the field holding each member's position; the public `positionedBy()` on
     * a to-many builder says which field that is.
     *
     * @return static
     */
    protected function positionMembersBy(string $field): static
    {
        if ($field === '') {
            throw new \InvalidArgumentException(\sprintf('Relation "%s" must name the field its members are positioned by.', $this->name));
        }

        $this->positionField = $field;

        return $this;
    }

    /**
     * Whether this builder builds a to-many relation, which alone may declare a
     * {@see defaultSort()} or {@see cannotChangeMembership()}.
     */
    protected function buildsToMany(): bool
    {
        return false;
    }

    /**
     * Snapshots the accumulated relation-shaping state into the immutable
     * {@see RelationState} a value object carries. Concrete {@see build()}
     * implementations pass this — alongside the inherited {@see FieldState} from
     * {@see fieldState()} — to their value-object constructor.
     */
    protected function relationState(): RelationState
    {
        if ($this->defaultSort !== [] && !$this->buildsToMany()) {
            throw new \LogicException(\sprintf(
                'Relation "%s" declares defaultSort(), but it is a to-one: only a to-many relation has members to order.',
                $this->name,
            ));
        }

        if ((!$this->allowsMembershipChange || $this->cannotChangeMembershipWhen !== null) && !$this->buildsToMany()) {
            throw new \LogicException(\sprintf(
                'Relation "%s" is a to-one, so it has no members to fix; cannotChangeMembership() is for to-many relations only.',
                $this->name,
            ));
        }

        return new RelationState(
            relatedTypes: $this->relatedTypes,
            inverseType: $this->inverseType,
            uriFieldName: $this->uriFieldName,
            includesLinks: $this->includesLinks,
            dataOnlyWhenLoaded: $this->dataOnlyWhenLoaded,
            allowsReplace: $this->allowsReplace,
            allowsRemove: $this->allowsRemove,
            exposesRelatedEndpoint: $this->exposesRelatedEndpoint,
            exposesRelationshipEndpoint: $this->exposesRelationshipEndpoint,
            allowsAdd: $this->allowsAdd,
            isIncludable: $this->isIncludable,
            cannotReplaceWhen: $this->cannotReplaceWhen,
            cannotRemoveWhen: $this->cannotRemoveWhen,
            cannotAddWhen: $this->cannotAddWhen,
            cannotBeIncludedWhen: $this->cannotBeIncludedWhen,
            securityRead: $this->securityRead,
            securityMutate: $this->securityMutate,
            isCountable: $this->isCountable,
            identifierMetaResolver: $this->identifierMetaResolver,
            identifierMetaFields: \array_values($this->identifierMetaFields),
            relationPaginator: $this->relationPaginator,
            paginationDisabled: $this->paginationDisabled,
            relationFilters: $this->relationFilters,
            relationSorts: $this->relationSorts,
            exampleId: $this->exampleId,
            defaultSort: $this->defaultSort,
            positionField: $this->positionField,
            allowsMembershipChange: $this->allowsMembershipChange,
            cannotChangeMembershipWhen: $this->cannotChangeMembershipWhen,
            dropsAllFilterDefaults: $this->dropsAllFilterDefaults,
            droppedFilterDefaults: $this->droppedFilterDefaults,
        );
    }
}
