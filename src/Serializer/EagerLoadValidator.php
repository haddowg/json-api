<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Serializer;

use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\SerializerResolverInterface;

/**
 * Validates a resource type's `on()` eager-load declarations
 * ({@see DeclaresEagerLoadsInterface::eagerLoadRelationshipPaths()}) **at boot /
 * container warm-up**, so an author mistake fails fast (at `cache:clear` / deploy)
 * rather than as a runtime 500 on a user request.
 *
 * Every entry is the to-one relation chain an `on()` attribute flattens a scalar
 * from (`'author'` single-hop, or `'publisher.country'` multi-hop). The validator
 * walks **every segment of every chain** across resource types (resolving each
 * segment's relation hidden-inclusively, then following
 * {@see RelationInterface::relatedTypes()} to the next type's serializer via the
 * {@see SerializerResolverInterface}) and throws a developer-facing
 * {@see \LogicException} on either fault at ANY depth:
 *
 *  - an **unknown segment** — a typo that names no declared relation, so the chain
 *    would silently no-op;
 *  - a **to-many segment** — `on()` flattens a single scalar from a to-one chain,
 *    so a to-many segment at any depth is not flattenable (use `?include` to
 *    materialise a to-many collection instead).
 *
 * A segment may be `hidden()` (the idiomatic internal association) or visible —
 * both are first-class, because the chain is to-one: eager-loading a to-one never
 * flips its linkage rendering, so there is no leakage to guard against. A
 * polymorphic / inventory-less segment whose next type cannot be resolved to a
 * single relation-declaring serializer is **left unwalked** (the walk stops on that
 * branch, NOT thrown) — exactly as the host's include walk leaves it unbatched.
 *
 * The rule is pure core metadata ({@see RelationInterface::isToMany()} +
 * cross-type resolution via the resolver), so it is reusable and unit-testable
 * here; a host invokes it once per registered resource at warm-up.
 */
final class EagerLoadValidator
{
    public function __construct(private readonly SerializerResolverInterface $resolver) {}

    /**
     * Validates every `on()` eager-load chain `$serializer` declares for the
     * resource `$type`. A serializer that declares no eager loads (it does not
     * implement {@see DeclaresEagerLoadsInterface} — a bare/standalone serializer)
     * is a no-op.
     *
     * @throws \LogicException on an unknown segment or a to-many segment, at any
     *                         depth of any chain
     */
    public function validate(string $type, SerializerInterface $serializer): void
    {
        if (!$serializer instanceof DeclaresEagerLoadsInterface) {
            return;
        }

        foreach ($serializer->eagerLoadRelationshipPaths() as $path) {
            $this->validatePath($type, $serializer, $path);
        }
    }

    /**
     * Walks a single (possibly dotted) `on()` chain segment by segment, resolving
     * each segment's relation and following it to the next type's serializer.
     */
    private function validatePath(string $type, SerializerInterface $serializer, string $path): void
    {
        $currentType = $type;
        $currentSerializer = $serializer;

        foreach (\explode('.', $path) as $segment) {
            $relation = $this->relationOf($currentSerializer, $segment);

            if ($relation === null) {
                throw new \LogicException(\sprintf(
                    'on() path "%s" on resource type "%s" names an unknown relation '
                    . '"%s" (on type "%s"): no such relation is declared. Fix the typo '
                    . 'or drop the on() entry.',
                    $path,
                    $type,
                    $segment,
                    $currentType,
                ));
            }

            if ($relation->isToMany()) {
                throw new \LogicException(\sprintf(
                    'on() path "%s" on resource type "%s" names "%s" (on type "%s"), a '
                    . 'to-many relation. on() flattens a scalar from a to-one chain — a '
                    . 'to-many is not flattenable; use ?include to materialise the '
                    . 'collection instead.',
                    $path,
                    $type,
                    $segment,
                    $currentType,
                ));
            }

            // Follow the relation to the next type to validate the remaining
            // segments. A polymorphic relation (more than one related type), or a
            // related type whose serializer cannot be resolved or does not declare a
            // relation inventory, cannot be walked to a single next type — leave that
            // branch unwalked (stop), exactly as the host's include walk leaves it
            // unbatched. NOT a throw.
            $next = $this->nextSerializer($relation);
            if ($next === null) {
                return;
            }

            [$currentType, $currentSerializer] = $next;
        }
    }

    /**
     * Resolves the relation `$segment` names on `$serializer`, hidden-inclusively.
     * A serializer that declares no relation inventory (it does not implement
     * {@see DeclaresRelationsInterface}) resolves nothing.
     */
    private function relationOf(SerializerInterface $serializer, string $segment): ?RelationInterface
    {
        if (!$serializer instanceof DeclaresRelationsInterface) {
            return null;
        }

        return $serializer->relationNamedIncludingHidden($segment);
    }

    /**
     * Follows `$relation` to its single related type's serializer, or `null` when
     * the next type cannot be resolved to a single relation-declaring serializer
     * (polymorphic — more than one related type — or unregistered, or a bare
     * serializer with no relation inventory). The walk stops there, never throwing
     * for an unwalkable branch.
     *
     * @return array{string, SerializerInterface}|null
     */
    private function nextSerializer(RelationInterface $relation): ?array
    {
        $relatedTypes = $relation->relatedTypes();
        if (\count($relatedTypes) !== 1) {
            return null;
        }

        $nextType = $relatedTypes[0];
        if (!$this->resolver->hasSerializerFor($nextType)) {
            return null;
        }

        $nextSerializer = $this->resolver->serializerFor($nextType);
        if (!$nextSerializer instanceof DeclaresRelationsInterface) {
            return null;
        }

        return [$nextType, $nextSerializer];
    }
}
