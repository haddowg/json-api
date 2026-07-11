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
 * The readonly base for a built {@see RelationInterface} value object — the
 * relation parallel of {@see AbstractFieldValue}. It holds the immutable
 * {@see RelationState} snapshot an {@see AbstractRelationBuilder} produced (beside
 * the inherited {@see FieldState}) and implements the whole relationship
 * consumption surface off it: the type / cardinality / link / linkage accessors,
 * the replace / add / remove / include gates, security, pagination, the
 * relation-scoped filters / sorts, and the serialize/hydrate routing relationships
 * use.
 *
 * The attribute serialize/hydrate paths are not used for relations — the schema
 * routes through {@see buildRelationship()} / {@see hydrateRelationship()} — so the
 * inherited {@see serialize()} returns the related value extraction and
 * {@see hydrate()} is a no-op (relationship hydration runs via the request's parsed
 * linkage, not a raw attribute value). Concrete value objects are `final readonly`
 * (or non-final where a sibling extends them) and implement {@see buildRelationship()}
 * and {@see isToMany()}; the fluent authoring surface lives on the mirror
 * {@see AbstractRelationBuilder}.
 */
abstract readonly class AbstractRelationValue extends AbstractFieldValue implements RelationInterface
{
    public function __construct(
        FieldState $state,
        protected RelationState $relationState,
    ) {
        parent::__construct($state);
    }

    public function relatedTypes(): array
    {
        return $this->relationState->relatedTypes;
    }

    public function includesLinks(): bool
    {
        return $this->relationState->includesLinks;
    }

    /**
     * Whether this relation emits its linkage `data` (its resource identifier(s),
     * distinct from the `self`/`related` links) only when the related value is
     * already loaded — the per-type default ({@see RelationState::$dataOnlyWhenLoaded}),
     * which {@see AbstractRelationBuilder::withData()} overrides to eager. Read by
     * the load-state seam.
     */
    public function emitsDataOnlyWhenLoaded(): bool
    {
        return $this->relationState->dataOnlyWhenLoaded;
    }

    public function readValue(mixed $model, JsonApiRequestInterface $request): mixed
    {
        return $this->relatedValue($model, $request, $this->state->name);
    }

    public function resolveSerializer(mixed $related, SerializerResolverInterface $resolver): ?SerializerInterface
    {
        $monomorphic = \count($this->relationState->relatedTypes) === 1;

        foreach ($this->relationState->relatedTypes as $type) {
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

    public function hydrateRelationship(mixed $model, object $relationship): mixed
    {
        if ($this->state->fillUsing !== null) {
            $result = ($this->state->fillUsing)($model, $relationship, [], $this->state->name);

            return $result ?? $model;
        }

        return $this->applyRelationship($model, $relationship);
    }

    public function allowsReplace(): bool
    {
        return $this->relationState->allowsReplace;
    }

    public function allowsReplaceFor(JsonApiRequestInterface $request, mixed $model): bool
    {
        return $this->resolveAllows($this->relationState->allowsReplace, $this->relationState->cannotReplaceWhen, $request, $model);
    }

    public function allowsRemove(): bool
    {
        return $this->relationState->allowsRemove;
    }

    public function allowsRemoveFor(JsonApiRequestInterface $request, mixed $model): bool
    {
        return $this->resolveAllows($this->relationState->allowsRemove, $this->relationState->cannotRemoveWhen, $request, $model);
    }

    public function exposesRelatedEndpoint(): bool
    {
        return $this->relationState->exposesRelatedEndpoint;
    }

    public function exposesRelationshipEndpoint(): bool
    {
        return $this->relationState->exposesRelationshipEndpoint;
    }

    public function allowsAdd(): bool
    {
        return $this->relationState->allowsAdd;
    }

    public function allowsAddFor(JsonApiRequestInterface $request, mixed $model): bool
    {
        return $this->resolveAllows($this->relationState->allowsAdd, $this->relationState->cannotAddWhen, $request, $model);
    }

    public function isIncludable(): bool
    {
        return $this->relationState->isIncludable;
    }

    public function isIncludableFor(JsonApiRequestInterface $request, mixed $model): bool
    {
        return $this->resolveAllows($this->relationState->isIncludable, $this->relationState->cannotBeIncludedWhen, $request, $model);
    }

    /**
     * The declared read security for this relation's read endpoints (see
     * {@see AbstractRelationBuilder::security()}), or `null` to inherit the owning
     * resource's read security.
     */
    public function securityRead(): string|bool|null
    {
        return $this->relationState->securityRead;
    }

    /**
     * The declared mutation security for this relation's relationship-mutation
     * endpoints (see {@see AbstractRelationBuilder::security()}), or `null` to
     * inherit the owning resource's update security.
     */
    public function securityMutate(): string|bool|null
    {
        return $this->relationState->securityMutate;
    }

    public function isCountable(): bool
    {
        return $this->relationState->isCountable;
    }

    /**
     * The effective paginator for this relation's related-collection endpoint
     * (`GET /{type}/{id}/{rel}`). When {@see AbstractRelationBuilder::withoutPagination()}
     * disabled it, returns `null` (fetch-all) regardless of `$fallback` — the opt-out
     * short-circuits *before* the fallback so the fallback can never override it.
     * Otherwise returns this relation's own paginator
     * ({@see AbstractRelationBuilder::paginate()}) when set, else `$fallback`. A
     * to-one relation has no collection and ignores this.
     */
    public function pagination(?\haddowg\JsonApi\Pagination\PaginatorInterface $fallback): ?\haddowg\JsonApi\Pagination\PaginatorInterface
    {
        return $this->relationState->paginationDisabled ? null : ($this->relationState->relationPaginator ?? $fallback);
    }

    public function filters(): array
    {
        return $this->relationState->relationFilters;
    }

    public function allFilters(): array
    {
        return \array_values(\array_map(
            static fn(\haddowg\JsonApi\Resource\Filter\FilterInterface|\haddowg\JsonApi\Resource\Filter\FilterBuilderInterface $filter): \haddowg\JsonApi\Resource\Filter\FilterInterface => $filter instanceof \haddowg\JsonApi\Resource\Filter\FilterBuilderInterface
                ? $filter->build()
                : $filter,
            $this->relationState->relationFilters,
        ));
    }

    public function sorts(): array
    {
        return $this->relationState->relationSorts;
    }

    /**
     * The URI segment for this relationship.
     */
    public function uriFieldName(): string
    {
        return $this->relationState->uriFieldName ?? $this->state->name;
    }

    public function constraints(): array
    {
        $constraints = parent::constraints();

        if ($this->relationState->relatedTypes !== []) {
            $constraints[] = new RelationshipType($this->relationState->relatedTypes);
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

    public function applyToMany(mixed $model, object $relationship, Mode $mode): mixed
    {
        if (!$relationship instanceof InputToMany) {
            return $model;
        }

        if ($this->state->fillUsing !== null) {
            $result = ($this->state->fillUsing)($model, $relationship, ['mode' => $mode], $this->state->name);

            return $result ?? $model;
        }

        $column = $this->state->column;
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
     * Resolves the related domain value(s) from the parent model — a single
     * object for a to-one relation, an iterable for a to-many one.
     */
    protected function relatedValue(mixed $model, JsonApiRequestInterface $request, string $name): mixed
    {
        if ($this->state->extractUsing !== null) {
            return ($this->state->extractUsing)($model, $request, $name);
        }

        return Accessor::get($model, $this->state->column ?? $name);
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
        $column = $this->state->column;
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
     * {@see AbstractRelationBuilder::withData()}) and the injected load-state
     * predicate reports the related value is *not* loaded, the linkage data read is
     * deferred behind a callable and the relationship is flagged
     * {@see AbstractRelationship::omitDataWhenNotIncluded()}: the transformer omits
     * the `data` member (emitting links only) unless the relationship is included,
     * in which case the callable runs and the value is read as today (include-wins).
     * Otherwise the value is read eagerly and the data member is set, exactly as
     * before.
     */
    protected function buildToOne(
        mixed $model,
        JsonApiRequestInterface $request,
        SerializerResolverInterface $resolver,
    ): OutputToOne {
        $relationship = OutputToOne::create();

        $type = $this->relationState->relatedTypes[0] ?? null;
        if ($type !== null && $resolver->hasSerializerFor($type)) {
            $serializer = $resolver->serializerFor($type);
            if ($this->shouldDeferLinkage($model, $resolver)) {
                $relationship
                    ->setDataAsCallable(fn(): mixed => $this->relatedValue($model, $request, $this->state->name), $serializer)
                    ->omitDataWhenNotIncluded();
            } else {
                // Always bind the serializer (even for a null related value) so the
                // relationship carries its resource: an empty to-one then renders
                // `data: null` rather than omitting the data member, which the
                // relationship-linkage endpoint (`/relationships/{name}`) requires
                // per the spec. The data member stays sparse in a full resource
                // document via the transformer's include/current-relationship gate,
                // so omitting it there is unaffected.
                $relationship->setData($this->relatedValue($model, $request, $this->state->name), $serializer);
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
        SerializerResolverInterface $resolver,
    ): OutputToMany {
        $relationship = OutputToMany::create();

        $type = $this->relationState->relatedTypes[0] ?? null;
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
                    ->setDataAsCallable(fn(): mixed => $this->relatedValue($model, $request, $this->state->name), $serializer)
                    ->omitDataWhenNotIncluded();
            } else {
                // Always bind the serializer (mirrors buildToOne): a to-many over a
                // null/absent related value then renders `data: []` rather than
                // omitting it, so the relationship-linkage endpoint is spec-correct.
                $relationship->setData($this->relatedValue($model, $request, $this->state->name), $serializer);
            }
        }

        $this->finalizeToMany($relationship, $model, $request, $resolver);

        return $relationship;
    }

    /**
     * Finalizes a to-one relationship: convention links + the per-(parent, relation)
     * identifier meta. The shared tail of {@see buildToOne()} and {@see MorphTo}.
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
     * {@see MorphToMany} — applied in one order so a fifth relation type cannot
     * reintroduce an ordering drift.
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
     * emitted. The injected resolver owns the page-1 windowing and the plain-form
     * link translation; core only attaches the result.
     */
    protected function resolvePagination(
        mixed $model,
        JsonApiRequestInterface $request,
        SerializerResolverInterface $resolver,
    ): ?\haddowg\JsonApi\Schema\Relationship\RelationshipPagination {
        return $resolver->relationshipPagination()?->paginateRelationship($model, $this, $request);
    }

    /**
     * Resolves the out-of-band linkage `data` for this to-many relation on `$model`
     * under the Relationship Queries profile — the windowed page a host supplies per
     * (parent, relation) — or `null` when none is supplied, in which case linkage is
     * read off the model as before. The injected resolver owns the windowing; core
     * only reads the supplied page back.
     */
    protected function relationshipLinkage(
        mixed $model,
        JsonApiRequestInterface $request,
        SerializerResolverInterface $resolver,
    ): ?\haddowg\JsonApi\Schema\Relationship\RelationshipLinkage {
        return $resolver->relationshipLinkage()?->linkageForRelationship($model, $this, $request);
    }

    /**
     * The relationship-object `meta` this relation contributes for `$model` — the
     * general per-relationship meta-contribution hook merged onto the built
     * relationship by {@see buildToMany()}. Its first consumer is the countable
     * relation `meta.total`. With no resolver injected, a non-countable relation, or
     * a relation the request did not name, this returns `[]` and no meta is emitted.
     * Override (calling `parent`) to contribute further relationship meta.
     *
     * @return array<string, mixed>
     */
    protected function relationshipMeta(
        mixed $model,
        JsonApiRequestInterface $request,
        SerializerResolverInterface $resolver,
    ): array {
        $meta = [];

        $total = $this->resolveCount($model, $request, $resolver);
        if ($total !== null) {
            $meta['total'] = $total;
        }

        return $meta;
    }

    /**
     * Attaches this relation's {@see AbstractRelationBuilder::identifierMeta()}
     * resolver (if any) onto a freshly built relationship, bound to the owning
     * `$model` and `$request`, so every resource identifier the relationship renders
     * in its linkage is augmented with the parent-aware meta. A no-op when no
     * resolver was declared.
     */
    protected function applyIdentifierMeta(
        AbstractRelationship $relationship,
        mixed $model,
        JsonApiRequestInterface $request,
    ): void {
        $resolver = $this->relationState->identifierMetaResolver;
        if ($resolver === null) {
            return;
        }

        $relationship->withIdentifierMeta(
            static fn(mixed $related): array => $resolver($model, $related, $request),
        );
    }

    /**
     * Whether the linkage data read for this relation should be deferred and the
     * data member omitted-unless-included, per the load-aware policy. True only
     * when the relation is lazy (the per-type default, not overridden by
     * {@see AbstractRelationBuilder::withData()}), it carries the convention links,
     * an injected {@see \haddowg\JsonApi\Serializer\RelationshipLoadStateInterface}
     * is present, and that predicate reports the related value is *not* loaded.
     * With no predicate injected the relation is treated as loaded (standalone
     * default).
     */
    protected function shouldDeferLinkage(
        mixed $model,
        SerializerResolverInterface $resolver,
    ): bool {
        if ($this->relationState->dataOnlyWhenLoaded === false || $this->relationState->includesLinks === false) {
            return false;
        }

        $loadState = $resolver->relationshipLoadState();
        if ($loadState === null) {
            return false;
        }

        return $loadState->isRelationshipLoaded($model, $this) === false;
    }

    /**
     * Builds the output relationship value object the serializer emits for
     * `$model`, resolving the related type's serializer through `$resolver`.
     * Abstract — each concrete relation implements it.
     */
    abstract public function buildRelationship(
        mixed $model,
        JsonApiRequestInterface $request,
        SerializerResolverInterface $resolver,
    ): AbstractRelationship;

    /**
     * Applies the convention `self`/`related` links to a relationship when this
     * relation emits links — the shared link tail of every relationship builder.
     */
    private function applyConventionLinks(AbstractRelationship $relationship): void
    {
        if ($this->relationState->includesLinks) {
            $relationship->withConventionLinks(
                $this->uriFieldName(),
                $this->relationState->exposesRelationshipEndpoint,
                $this->relationState->exposesRelatedEndpoint,
            );
        }
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

    /**
     * Resolves the countable cardinality for this relation on `$model`, or `null`
     * when no count should be emitted: the relation is not
     * {@see AbstractRelationBuilder::countable()}, the request did not name it in
     * `?withCount`, no {@see \haddowg\JsonApi\Serializer\RelationshipCountInterface}
     * was injected, or the resolver itself returned `null` (no count available for
     * this parent).
     */
    private function resolveCount(
        mixed $model,
        JsonApiRequestInterface $request,
        SerializerResolverInterface $resolver,
    ): ?int {
        if ($this->relationState->isCountable === false || $request->countsRelationship($this->state->name) === false) {
            return null;
        }

        return $resolver->relationshipCount()?->countRelationship($model, $this);
    }
}
