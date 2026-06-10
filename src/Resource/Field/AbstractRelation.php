<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship as InputToMany;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship as InputToOne;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Constraint\RelationshipType;
use haddowg\JsonApi\Schema\Relationship\AbstractRelationship;
use haddowg\JsonApi\Schema\Relationship\ToManyRelationship as OutputToMany;
use haddowg\JsonApi\Schema\Relationship\ToOneRelationship as OutputToOne;

/**
 * Convenience base for {@see Relation} fields. Reuses {@see AbstractField}'s
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

    protected bool $retainFieldName = false;

    protected bool $includesLinks = true;

    protected bool $linkageOnlyWhenLoaded = false;

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
     * @return static
     */
    public function retainFieldName(): static
    {
        $this->retainFieldName = true;

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

    public function hydrate(mixed $model, mixed $value, array $data, JsonApiRequestInterface $request): mixed
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

    /**
     * Writes the parsed input relationship into the domain object. Default:
     * store the related id(s) on the field's column. Override for richer
     * cardinality handling.
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
                $related = $this->relatedValue($model, $request, $this->name);
                if ($related !== null) {
                    $relationship->setData($related, $serializer);
                }
            }
        }

        if ($this->includesLinks) {
            $relationship->withConventionLinks($this->uriFieldName());
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
                $related = $this->relatedValue($model, $request, $this->name);
                if ($related !== null) {
                    $relationship->setData($related, $serializer);
                }
            }
        }

        if ($this->includesLinks) {
            $relationship->withConventionLinks($this->uriFieldName());
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
