<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Internal\ToOneTarget;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Schema\Relationship\AbstractRelationship;
use haddowg\JsonApi\Serializer\SerializerInterface;

/**
 * A polymorphic to-one relationship (`morphTo`): the related resource may be one
 * of several declared types, passed as the mandatory list to {@see make()}; the
 * related object's serializer is resolved at runtime by its own `getType()` — the
 * built, readonly value object the engine walks. Authors declare one with
 * {@see make()}, which returns a mutable {@see MorphToBuilder}.
 *
 * Declared with {@see MorphToBuilder::identifiedBy()}, the relation reads its
 * target's `[type, id]` off the parent instead, so its linkage never loads the
 * target. {@see storedIdentifiers()} gives a host the pairs of many parents grouped
 * by type, to load each type's targets in one query.
 */
final readonly class MorphTo extends AbstractRelationValue implements PolymorphicRelationInterface
{
    /**
     * @param (\Closure(mixed, JsonApiRequestInterface): mixed)|null $identifiedBy validated per call, since nothing checks a closure's return type
     */
    public function __construct(
        FieldState $state,
        RelationState $relationState,
        private ?\Closure $identifiedBy = null,
    ) {
        parent::__construct($state, $relationState);
    }

    /**
     * @param non-empty-list<string> $types
     */
    public static function make(string $name, array $types): MorphToBuilder
    {
        return MorphToBuilder::make($name, $types);
    }

    public function isToMany(): bool
    {
        return false;
    }

    /**
     * Whether the relation was declared with {@see MorphToBuilder::identifiedBy()}, so
     * its target's identifier is read off the parent.
     */
    public function storesIdentifiers(): bool
    {
        return $this->identifiedBy !== null;
    }

    /**
     * The target's `[type, id]` as the parent stores it, with `id` the storage key
     * rather than its encoded wire form, or `null` when the relation is empty.
     *
     * @return array{0: string, 1: string|int}|null
     *
     * @throws \LogicException when the relation stores no identifiers, or the pair is
     *                         malformed or names a type the relation does not declare
     */
    public function storedIdentifier(mixed $model, JsonApiRequestInterface $request): ?array
    {
        if ($this->identifiedBy === null) {
            throw new \LogicException(\sprintf('MorphTo relation "%s" was not declared with identifiedBy(), so it stores no identifiers.', $this->state->name));
        }

        $pair = ($this->identifiedBy)($model, $request);
        if ($pair === null) {
            return null;
        }

        if (!\is_array($pair) || !\array_is_list($pair) || \count($pair) !== 2 || !\is_string($pair[0]) || !(\is_int($pair[1]) || (\is_string($pair[1]) && $pair[1] !== ''))) {
            throw new \LogicException(\sprintf(
                'The identifiedBy() closure of MorphTo relation "%s" on %s returned %s; it must return [type, id] or null.',
                $this->state->name,
                $this->declaringClass($model),
                \get_debug_type($pair),
            ));
        }

        if (!\in_array($pair[0], $this->relationState->relatedTypes, true)) {
            throw new \LogicException(\sprintf(
                'MorphTo relation "%s" on %s identified a target of type "%s", which it does not declare. Declared types: %s.',
                $this->state->name,
                $this->declaringClass($model),
                $pair[0],
                \implode(', ', $this->relationState->relatedTypes),
            ));
        }

        return $pair;
    }

    /**
     * The stored target ids of every model in `$models`, grouped by type in declared
     * order, each type's ids deduplicated in first-seen order and empty types left
     * out. Ids are storage keys, ready for one `id IN (...)` query per type. An empty
     * relation contributes nothing.
     *
     * @param iterable<mixed> $models
     *
     * @return array<string, non-empty-list<string|int>>
     *
     * @throws \LogicException as {@see storedIdentifier()} does
     */
    public function storedIdentifiers(iterable $models, JsonApiRequestInterface $request): array
    {
        $seen = [];
        foreach ($models as $model) {
            $pair = $this->storedIdentifier($model, $request);
            if ($pair !== null) {
                $seen[$pair[0]][(string) $pair[1]] ??= $pair[1];
            }
        }

        $grouped = [];
        foreach ($this->relationState->relatedTypes as $type) {
            if (isset($seen[$type])) {
                $grouped[$type] = \array_values($seen[$type]);
            }
        }

        return $grouped;
    }

    /**
     * An identified relation renders its linkage from the stored pair, which needs no
     * load, so its policy is never load-aware.
     */
    public function emitsDataOnlyWhenLoaded(): bool
    {
        return $this->identifiedBy === null && parent::emitsDataOnlyWhenLoaded();
    }

    public function buildRelationship(
        mixed $model,
        JsonApiRequestInterface $request,
        SerializerResolverInterface $resolver,
    ): AbstractRelationship {
        $relationship = \haddowg\JsonApi\Schema\Relationship\ToOneRelationship::create();
        if (!$this->isReadableFor($model, $request, $resolver)) {
            return $this->withheld($relationship);
        }

        // A polymorphic relation resolves the serializer from the related
        // object's own type, so it must read the related value. Under the
        // load-aware policy, defer that read (and the data member) unless the
        // relationship is included — see AbstractRelationValue::shouldDeferLinkage().
        if ($this->shouldDeferLinkage($model, $resolver)) {
            $deferredSerializer = $this->firstResolvableSerializer($resolver);

            if ($deferredSerializer !== null) {
                $relationship
                    ->setDataAsCallable(fn(): mixed => ToOneTarget::read($this, $model, $request, $resolver), $deferredSerializer)
                    ->omitDataWhenNotIncluded();
            }
        } else {
            $supplied = ToOneTarget::supplied($this, $model, $request, $resolver);
            if ($supplied === null && $this->identifiedBy !== null) {
                $this->bindStoredIdentifier($relationship, $model, $request, $resolver);
                $this->finalizeToOne($relationship, $model, $request);

                return $relationship;
            }

            $related = $supplied !== null ? $supplied->target : $this->relatedValue($model, $request, $this->state->name);
            // Resolve the serializer for whichever declared type reports the
            // related object's own type (the shared per-relation rule).
            $serializer = $related !== null ? $this->resolveSerializer($related, $resolver) : null;
            if ($serializer !== null) {
                $relationship->setData($related, $serializer);
            } elseif ($supplied !== null) {
                // A host-supplied none (or a target of no declared type) renders
                // `data: null`, which needs a serializer bound to the relationship.
                $fallback = $this->firstResolvableSerializer($resolver);
                if ($fallback !== null) {
                    $relationship->setData(null, $fallback);
                }
            }
        }

        $this->finalizeToOne($relationship, $model, $request);

        return $relationship;
    }

    protected function shouldDeferLinkage(mixed $model, SerializerResolverInterface $resolver): bool
    {
        return $this->identifiedBy === null && parent::shouldDeferLinkage($model, $resolver);
    }

    /**
     * Binds the stored pair as the linkage. The target is read off the parent only if
     * the relationship is included.
     */
    private function bindStoredIdentifier(
        AbstractRelationship $relationship,
        mixed $model,
        JsonApiRequestInterface $request,
        SerializerResolverInterface $resolver,
    ): void {
        $pair = $this->storedIdentifier($model, $request);
        if ($pair === null) {
            $fallback = $this->firstResolvableSerializer($resolver);
            if ($fallback !== null) {
                $relationship->setData(null, $fallback);
            }

            return;
        }

        [$type, $id] = $pair;
        if (!$resolver->hasSerializerFor($type)) {
            return;
        }

        $serializer = $resolver->serializerFor($type);
        $relationship->setStoredIdentifier(
            $type,
            self::wireId($serializer, $id),
            fn(): mixed => $this->relatedValue($model, $request, $this->state->name),
            $serializer,
        );
    }

    /**
     * The stored key as the target's serializer would render its id: through the id
     * field's encoder when the target type declares one.
     */
    private static function wireId(SerializerInterface $serializer, string|int $id): string
    {
        if ($serializer instanceof AbstractResource) {
            foreach ($serializer->allFields() as $field) {
                if ($field instanceof Id) {
                    $encoder = $field->encoder();

                    return $encoder !== null ? $encoder->encode($id) : (string) $id;
                }
            }
        }

        return (string) $id;
    }

    /**
     * The class that declared the identifiedBy() closure, normally the owning
     * resource, for an error message.
     */
    private function declaringClass(mixed $model): string
    {
        $scope = $this->identifiedBy !== null ? (new \ReflectionFunction($this->identifiedBy))->getClosureScopeClass() : null;

        return $scope !== null ? $scope->getName() : 'a parent of type ' . \get_debug_type($model);
    }

    private function firstResolvableSerializer(SerializerResolverInterface $resolver): ?SerializerInterface
    {
        foreach ($this->relationState->relatedTypes as $type) {
            if ($resolver->hasSerializerFor($type)) {
                return $resolver->serializerFor($type);
            }
        }

        return null;
    }
}
