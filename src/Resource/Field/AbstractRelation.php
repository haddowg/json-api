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
     * Builds a to-one output relationship from the resolved related object.
     */
    protected function buildToOne(
        mixed $related,
        JsonApiRequestInterface $request,
        \haddowg\JsonApi\Resource\SerializerResolverInterface $resolver,
    ): OutputToOne {
        $relationship = OutputToOne::create();

        if ($related !== null) {
            $type = $this->relatedTypes[0] ?? null;
            if ($type !== null && $resolver->hasSerializerFor($type)) {
                $relationship->setData($related, $resolver->serializerFor($type));
            }
        }

        if ($this->includesLinks) {
            $relationship->withConventionLinks($this->uriFieldName());
        }

        return $relationship;
    }

    /**
     * Builds a to-many output relationship from the resolved related collection.
     */
    protected function buildToMany(
        mixed $related,
        JsonApiRequestInterface $request,
        \haddowg\JsonApi\Resource\SerializerResolverInterface $resolver,
    ): OutputToMany {
        $relationship = OutputToMany::create();

        $type = $this->relatedTypes[0] ?? null;
        if ($related !== null && $type !== null && $resolver->hasSerializerFor($type)) {
            $relationship->setData($related, $resolver->serializerFor($type));
        }

        if ($this->includesLinks) {
            $relationship->withConventionLinks($this->uriFieldName());
        }

        return $relationship;
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
