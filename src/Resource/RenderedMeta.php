<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

use haddowg\JsonApi\Resource\Field\AbstractRelationValue;
use haddowg\JsonApi\Resource\Field\PivotRelationInterface;
use haddowg\JsonApi\Serializer\DeclaresRelationsInterface;
use haddowg\JsonApi\Serializer\DeclaresResourceMetaInterface;

/**
 * The debug check adapters run over a rendered document's resource objects: each one's
 * `meta` against its type's declared {@see DeclaresResourceMetaInterface::resourceMeta()},
 * and each linkage identifier's `meta` against its relation's declared identifier meta and
 * pivot fields. A type or relation that declares nothing is not checked, so its `meta`
 * stays open. Adapters call it in debug only and refuse a violation with
 * {@see ResourceMetaMismatch}, so a mistake fails loudly in development and costs nothing
 * in production.
 */
final class RenderedMeta
{
    /**
     * What is wrong with the resource objects of a rendered document, `data` and
     * `included`, each violation naming the object it was found on.
     *
     * @param array<array-key, mixed>        $document    the rendered document
     * @param \Closure(string): (object|null) $serializers the serializer of a type, or `null` for one it cannot resolve
     *
     * @return list<string>
     */
    public static function violations(array $document, \Closure $serializers): array
    {
        $objects = [];
        $data = $document['data'] ?? null;
        if (\is_array($data)) {
            $objects = \array_is_list($data) ? $data : [$data];
        }
        $included = $document['included'] ?? null;
        if (\is_array($included)) {
            $objects = [...$objects, ...$included];
        }

        $violations = [];
        foreach ($objects as $object) {
            if (\is_array($object)) {
                $violations = [...$violations, ...self::objectViolations($object, $serializers)];
            }
        }

        return $violations;
    }

    /**
     * Refuses a rendered document whose resource objects' `meta`, or whose linkage
     * identifiers' `meta`, does not match the declarations.
     *
     * @param array<array-key, mixed>        $document
     * @param \Closure(string): (object|null) $serializers
     *
     * @throws ResourceMetaMismatch
     */
    public static function assertConforms(array $document, \Closure $serializers): void
    {
        $violations = self::violations($document, $serializers);
        if ($violations !== []) {
            throw new ResourceMetaMismatch(\sprintf('The rendered resource meta does not match its declaration: %s.', \implode('; ', $violations)));
        }
    }

    /**
     * @param array<array-key, mixed>        $object
     * @param \Closure(string): (object|null) $serializers
     *
     * @return list<string>
     */
    private static function objectViolations(array $object, \Closure $serializers): array
    {
        $type = $object['type'] ?? null;
        if (!\is_string($type)) {
            return [];
        }
        $serializer = $serializers($type);
        if ($serializer === null) {
            return [];
        }
        $label = \sprintf('"%s" "%s"', $type, \is_scalar($object['id'] ?? null) ? (string) $object['id'] : '');

        $violations = [];
        if ($serializer instanceof DeclaresResourceMetaInterface) {
            $meta = $object['meta'] ?? [];
            // A member a pivot relation's related endpoint renders carries its link's `pivot`
            // beside the declared members.
            foreach (ResourceMeta::readShapeOf($serializer, $type)->violations(\is_array($meta) ? $meta : [], ['pivot']) as $violation) {
                $violations[] = \sprintf('the meta of %s: %s', $label, $violation);
            }
        }

        $relationships = $object['relationships'] ?? null;
        if (!$serializer instanceof DeclaresRelationsInterface || !\is_array($relationships)) {
            return $violations;
        }
        foreach ($relationships as $name => $relationship) {
            $relation = $serializer->relationNamedIncludingHidden((string) $name);
            $linkage = \is_array($relationship) ? ($relationship['data'] ?? null) : null;
            if ($relation === null || !\is_array($linkage)) {
                continue;
            }
            $identifiers = \array_is_list($linkage) ? $linkage : [$linkage];
            foreach ($identifiers as $identifier) {
                if (!\is_array($identifier)) {
                    continue;
                }
                foreach (self::identifierViolations($relation, $identifier) as $violation) {
                    $violations[] = \sprintf('the linkage of %s relationship "%s": %s', $label, (string) $name, $violation);
                }
            }
        }

        return $violations;
    }

    /**
     * What is wrong with one linkage identifier's `meta`: a member the declared identifier
     * meta does not name, beside `pivot`, and inside `pivot` a member the pivot fields do
     * not name. Each object is checked only when the relation declares it.
     *
     * @param array<array-key, mixed> $identifier
     *
     * @return list<string>
     */
    public static function identifierViolations(Field\RelationInterface $relation, array $identifier): array
    {
        $declared = $relation instanceof AbstractRelationValue ? $relation->identifierMetaShape() : MetaShape::none();
        $pivot = $relation instanceof PivotRelationInterface && ($relation->pivotFields() !== [] || $relation->pivotAdditionalProperties() !== null)
            ? $relation->pivotMetaShape()
            : null;
        if ($declared->isEmpty() && $pivot === null) {
            return [];
        }

        $meta = $identifier['meta'] ?? [];
        $meta = \is_array($meta) ? $meta : [];
        $violations = [];
        $outer = $meta;
        if ($pivot !== null) {
            unset($outer['pivot']);
            $values = $meta['pivot'] ?? [];
            foreach ($pivot->violations(\is_array($values) ? $values : []) as $violation) {
                $violations[] = 'pivot ' . $violation;
            }
        }
        // Identifier meta a relation's resolver supplies with no declared members stays open.
        if (!$declared->isEmpty()) {
            $violations = [...$violations, ...$declared->violations($outer)];
        }

        return $violations;
    }
}
