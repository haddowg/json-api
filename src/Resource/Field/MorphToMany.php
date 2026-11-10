<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Collection\LinkRows\InMemoryLinkRows;
use haddowg\JsonApi\Collection\LinkRows\LinkRowQuery;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\Internal\StoredPair;
use haddowg\JsonApi\Resource\ProvidesOutputTimezoneInterface;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Resource\Sort\RelationSorts;
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
 *
 * An identified relation can declare **pivot fields** on the rows it reads its pairs
 * from, exactly as a {@see BelongsToMany} declares them on its join table. Each member's
 * readable fields render as `meta.pivot` on its identifier, its relationship and related
 * endpoints sort by them and filter by the relation's `pivot.`-prefixed filters, all over
 * the link rows ({@see LinkRowQuery}).
 */
final readonly class MorphToMany extends AbstractRelationValue implements PolymorphicRelationInterface, StoresIdentifiersInterface, PivotRelationInterface
{
    use ReadsPivotFields;

    /**
     * @param (\Closure(mixed, JsonApiRequestInterface): mixed)|null $identifiedBy validated per call, since nothing checks a closure's return type
     * @param array<string, FieldInterface>                          $pivotFields  the declared pivot fields, keyed by field name (declaration order preserved)
     */
    public function __construct(
        FieldState $state,
        RelationState $relationState,
        private ?\Closure $identifiedBy = null,
        private array $pivotFields = [],
        private ?string $pivotThrough = null,
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
        return \array_map(
            static fn(IdentifiedMember $member): array => [$member->type, $member->id],
            $this->identifiedMembers($model, $request),
        );
    }

    /**
     * The members the parent stores, in order, each carrying the link row its pair was
     * read from when the `identifiedBy()` closure returned one. A pair repeated in the
     * list is kept once, at its first position. These are the rows the relation's pivot
     * fields read and a {@see LinkRowQuery} filters, sorts and pages.
     *
     * @return list<IdentifiedMember>
     *
     * @throws \LogicException when the relation stores no identifiers, or the list or
     *                         a member is malformed, names a type the relation does
     *                         not declare, or lacks the row its stored pivot fields
     *                         are read from
     */
    public function identifiedMembers(mixed $model, JsonApiRequestInterface $request): array
    {
        if ($this->identifiedBy === null) {
            throw new \LogicException(\sprintf('MorphToMany relation "%s" was not declared with identifiedBy(), so it stores no identifiers.', $this->state->name));
        }

        $returned = ($this->identifiedBy)($model, $request);
        if (!\is_iterable($returned)) {
            throw $this->malformed($model, $returned);
        }

        $members = [];
        $seen = [];
        foreach ($returned as $member) {
            if (!StoredPair::isMember($member)) {
                throw $this->malformed($model, $member);
            }

            $this->assertDeclared($model, $member[0]);
            $identified = IdentifiedMember::of([$member[0], $member[1]], row: $member[2] ?? null);
            $this->assertRow($identified, $model);
            $key = $member[0] . "\0" . $member[1];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $members[] = $identified;
            }
        }

        return $members;
    }

    /**
     * The `meta.pivot` one member renders: each pivot field readable for this request,
     * keyed by name, its stored value read off the member's row and cast by the field,
     * or produced by its hook. A stored field the row doesn't hold renders `null`. The
     * same values a host renders on the member's resource object on the related
     * endpoint. `$outputTimezone` is the server's default output zone, which a date
     * field adopts unless it set its own.
     *
     * @return array<string, mixed>
     */
    public function pivotValues(IdentifiedMember $member, mixed $parent, JsonApiRequestInterface $request, ?string $outputTimezone = null): array
    {
        $stored = [];
        foreach ($this->storedPivotFields() as $field) {
            $stored[$field->name()] = $member->row === null ? null : Accessor::get($member->row, $field->column() ?? $field->name());
        }

        $hooked = $this->hookedPivotValues($parent, $member->target, $stored, $request, $outputTimezone);

        $values = [];
        foreach ($this->readablePivotFieldsFor($request, $member->target) as $field) {
            $name = $field->name();
            if (\array_key_exists($name, $hooked)) {
                $values[$name] = $hooked[$name];

                continue;
            }

            if ($outputTimezone !== null && $field instanceof AcceptsDefaultOutputTimezoneInterface) {
                $field = $field->withDefaultOutputTimezone($outputTimezone);
            }
            $values[$name] = $field->serializeWithoutRequest([$field->column() ?? $name => $stored[$name] ?? null]);
        }

        return $values;
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
     * the parent stores, ordered by the relation's default sort over their rows. A
     * member whose type has no registered serializer is left out. A member with no
     * supplied target is matched, only if the relationship is included, against the
     * relation's usual value on the parent. Each carries its `meta.pivot` and its
     * identifier meta.
     *
     * @return list<array{type: string, id: string, resource: SerializerInterface, target: \Closure(): mixed, meta: array<string, mixed>}>
     */
    private function storedMembers(
        mixed $model,
        JsonApiRequestInterface $request,
        SerializerResolverInterface $resolver,
        ?RelationshipLinkage $linkage,
    ): array {
        if ($linkage !== null) {
            $members = $this->suppliedMembers($model, $linkage);
        } else {
            $members = $this->identifiedMembers($model, $request);
            $order = RelationSorts::ownDefault($this);
            if ($order !== []) {
                $members = (new InMemoryLinkRows())->apply($members, new LinkRowQuery($order))->items;
            }
        }

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

        $outputTimezone = $resolver instanceof ProvidesOutputTimezoneInterface ? $resolver->outputTimezone() : null;
        $identifierMeta = $this->relationState->identifierMetaResolver;

        $stored = [];
        foreach ($members as $member) {
            if (!$resolver->hasSerializerFor($member->type)) {
                continue;
            }

            $serializer = $resolver->serializerFor($member->type);
            $id = StoredPair::wireId($serializer, $member->id);
            $target = $member->target;
            $meta = $this->pivotFields === [] ? [] : ['pivot' => $this->pivotValues($member, $model, $request, $outputTimezone)];
            if ($identifierMeta !== null) {
                $meta = [...$meta, ...$identifierMeta($model, $member, $request)];
            }
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
                'meta' => $meta,
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
            $this->assertRow($member, $model);
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

    /**
     * A member of a relation whose pivot fields are stored must carry the row they are
     * read from.
     */
    private function assertRow(IdentifiedMember $member, mixed $model): void
    {
        if ($member->row !== null || $this->storedPivotFields() === []) {
            return;
        }

        throw new \LogicException(\sprintf(
            'MorphToMany relation "%s" on %s declares stored pivot fields, so each member must carry the link row they are read from; the "%s" member "%s" has none. Return [type, id, row] from identifiedBy(), or pass the row to IdentifiedMember.',
            $this->state->name,
            StoredPair::declaringClass($this->identifiedBy, $model),
            $member->type,
            (string) $member->id,
        ));
    }

    private function malformed(mixed $model, mixed $value): \LogicException
    {
        return new \LogicException(\sprintf(
            'The identifiedBy() closure of MorphToMany relation "%s" on %s returned %s; it must return a list of [type, id] pairs or [type, id, row] triples.',
            $this->state->name,
            StoredPair::declaringClass($this->identifiedBy, $model),
            \get_debug_type($value),
        ));
    }
}
