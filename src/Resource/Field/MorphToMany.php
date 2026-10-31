<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\Internal\StoredPair;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Schema\Relationship\AbstractRelationship;
use haddowg\JsonApi\Schema\Relationship\IdentifiedMember;
use haddowg\JsonApi\Schema\Relationship\RelationshipLinkage;
use haddowg\JsonApi\Schema\Relationship\ToManyRelationship as OutputToMany;
use haddowg\JsonApi\Serializer\PolymorphicSerializer;
use haddowg\JsonApi\Serializer\SerializerInterface;

/**
 * A polymorphic to-many relationship (`morphToMany`): the collection's members
 * may each be one of several declared types, passed as the mandatory list to
 * {@see make()}; each member's serializer is resolved at runtime by its own
 * `getType()` — the to-many parallel of {@see MorphTo}, and the built, readonly
 * value object the engine walks. The mixed-type members are rendered by binding a
 * {@see PolymorphicSerializer} that resolves and delegates per member. Authors
 * declare one with {@see make()}, which returns a mutable {@see MorphToManyBuilder}.
 *
 * Declared with {@see MorphToManyBuilder::identifiedBy()}, the relation reads its
 * members' `[type, id]` pairs off the parent instead, so its linkage never loads a
 * member. {@see storedIdentifiers()} gives a host the pairs of many parents grouped by
 * type, to load each type's members in one query.
 */
final readonly class MorphToMany extends AbstractRelationValue implements PolymorphicRelationInterface, StoresIdentifiersInterface
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
    public static function make(string $name, array $types): MorphToManyBuilder
    {
        return MorphToManyBuilder::make($name, $types);
    }

    public function isToMany(): bool
    {
        return true;
    }

    public function storesIdentifiers(): bool
    {
        return $this->identifiedBy !== null;
    }

    /**
     * The members' `[type, id]` pairs as the parent stores them, in order, with each
     * `id` the storage key rather than its encoded wire form. A pair repeated in the
     * list is kept once, at its first position.
     *
     * @return list<array{0: string, 1: string|int}>
     *
     * @throws \LogicException when the relation stores no identifiers, or the list or
     *                         a pair is malformed or names a type the relation does
     *                         not declare
     */
    public function storedIdentifierList(mixed $model, JsonApiRequestInterface $request): array
    {
        if ($this->identifiedBy === null) {
            throw new \LogicException(\sprintf('MorphToMany relation "%s" was not declared with identifiedBy(), so it stores no identifiers.', $this->state->name));
        }

        $pairs = ($this->identifiedBy)($model, $request);
        if (!\is_iterable($pairs)) {
            throw $this->malformed($model, $pairs);
        }

        $list = [];
        $seen = [];
        foreach ($pairs as $pair) {
            if (!StoredPair::isPair($pair)) {
                throw $this->malformed($model, $pair);
            }

            $this->assertDeclared($model, $pair[0]);
            $key = $pair[0] . "\0" . $pair[1];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $list[] = $pair;
            }
        }

        return $list;
    }

    public function storedIdentifiers(iterable $models, JsonApiRequestInterface $request): array
    {
        $seen = [];
        foreach ($models as $model) {
            foreach ($this->storedIdentifierList($model, $request) as [$type, $id]) {
                $seen[$type][(string) $id] ??= $id;
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
     * An identified relation renders its linkage from the stored pairs, which need no
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
        $relationship = OutputToMany::create();
        if (!$this->isReadableFor($model, $request, $resolver)) {
            return $this->withheld($relationship);
        }

        // One decorator drives every member: it resolves the per-member serializer
        // (matching the member's own type against a declared one) and delegates, so
        // a mixed-type collection renders correct per-member type/id/attributes
        // without any transformer or ToManyRelationship change.
        $serializer = new PolymorphicSerializer(function (mixed $object) use ($resolver): SerializerInterface {
            return $this->resolveSerializer($object, $resolver)
                ?? throw new \LogicException(\sprintf('No declared type of the "%s" relationship serializes the related object.', $this->state->name));
        });

        // Out-of-band linkage, as for a monomorphic to-many: a host may supply this
        // relation's members per (parent, relation), for example narrowed to the rows
        // each member type's provider admits, without writing them onto the parent.
        $linkage = $this->relationshipLinkage($model, $request, $resolver);
        if ($this->identifiedBy !== null) {
            $relationship->setStoredMembers($this->storedMembers($model, $request, $resolver, $linkage), $serializer);
        } elseif ($linkage !== null) {
            $relationship->setData($linkage->data, $serializer);
        } elseif ($this->shouldDeferLinkage($model, $resolver)) {
            $relationship
                ->setDataAsCallable(fn(): mixed => $this->relatedValue($model, $request, $this->state->name), $serializer)
                ->omitDataWhenNotIncluded();
        } else {
            $relationship->setData($this->relatedValue($model, $request, $this->state->name), $serializer);
        }

        // Convention links, the relationship-meta hook (its first consumer is the
        // countable `meta.total` — so a countable polymorphic to-many
        // named in `?withCount` renders its cardinality), identifier meta, and the
        // Relationship-Queries pagination links — the shared to-many tail.
        $this->finalizeToMany($relationship, $model, $request, $resolver);

        return $relationship;
    }

    protected function shouldDeferLinkage(mixed $model, SerializerResolverInterface $resolver): bool
    {
        return $this->identifiedBy === null && parent::shouldDeferLinkage($model, $resolver);
    }

    /**
     * The members to render as the linkage: the host-supplied ones, or else the pairs
     * the parent stores. A member whose type has no registered serializer is left out.
     * A member with no supplied target is matched, only if the relationship is
     * included, against the relation's usual value on the parent.
     *
     * @return list<array{type: string, id: string, resource: SerializerInterface, target: \Closure(): mixed}>
     */
    private function storedMembers(
        mixed $model,
        JsonApiRequestInterface $request,
        SerializerResolverInterface $resolver,
        ?RelationshipLinkage $linkage,
    ): array {
        $members = $linkage !== null
            ? $this->suppliedMembers($model, $linkage)
            : \array_map(static fn(array $pair): IdentifiedMember => IdentifiedMember::of($pair), $this->storedIdentifierList($model, $request));

        $onParent = null;
        $readParent = function () use (&$onParent, $model, $request): array {
            if ($onParent === null) {
                $value = $this->relatedValue($model, $request, $this->state->name);
                $onParent = [];
                foreach (\is_iterable($value) ? $value : [] as $object) {
                    if (\is_object($object)) {
                        $onParent[] = $object;
                    }
                }
            }

            return $onParent;
        };

        $stored = [];
        foreach ($members as $member) {
            if (!$resolver->hasSerializerFor($member->type)) {
                continue;
            }

            $serializer = $resolver->serializerFor($member->type);
            $id = StoredPair::wireId($serializer, $member->id);
            $target = $member->target;
            $stored[] = [
                'type' => $member->type,
                'id' => $id,
                'resource' => $serializer,
                'target' => $target !== null
                    ? static fn(): object => $target
                    : static function () use ($readParent, $serializer, $member, $id): ?object {
                        foreach ($readParent() as $object) {
                            if ($serializer->getType($object) === $member->type && $serializer->getId($object) === $id) {
                                return $object;
                            }
                        }

                        return null;
                    },
            ];
        }

        return $stored;
    }

    /**
     * @return list<IdentifiedMember>
     */
    private function suppliedMembers(mixed $model, RelationshipLinkage $linkage): array
    {
        $members = [];
        foreach (\is_iterable($linkage->data) ? $linkage->data : [] as $member) {
            if (!$member instanceof IdentifiedMember) {
                throw new \LogicException(\sprintf(
                    'The linkage supplied for MorphToMany relation "%s", declared with identifiedBy(), must list %s values; got %s.',
                    $this->state->name,
                    IdentifiedMember::class,
                    \get_debug_type($member),
                ));
            }

            $this->assertDeclared($model, $member->type);
            $members[] = $member;
        }

        return $members;
    }

    private function assertDeclared(mixed $model, string $type): void
    {
        if (!\in_array($type, $this->relationState->relatedTypes, true)) {
            throw new \LogicException(\sprintf(
                'MorphToMany relation "%s" on %s identified a member of type "%s", which it does not declare. Declared types: %s.',
                $this->state->name,
                StoredPair::declaringClass($this->identifiedBy, $model),
                $type,
                \implode(', ', $this->relationState->relatedTypes),
            ));
        }
    }

    private function malformed(mixed $model, mixed $value): \LogicException
    {
        return new \LogicException(\sprintf(
            'The identifiedBy() closure of MorphToMany relation "%s" on %s returned %s; it must return a list of [type, id] pairs.',
            $this->state->name,
            StoredPair::declaringClass($this->identifiedBy, $model),
            \get_debug_type($value),
        ));
    }
}
