<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field\Internal;

use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\StoredIdForms;
use haddowg\JsonApi\Resource\Internal\RegisteredResource;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;

/**
 * The checks and conversions shared by the relations declared with `identifiedBy()`.
 *
 * @internal
 */
final class StoredPair
{
    /**
     * Whether `$pair` is a `[type, id]` pair with a string type and a non-empty id.
     *
     * @phpstan-assert-if-true array{0: string, 1: string|int} $pair
     */
    public static function isPair(mixed $pair): bool
    {
        return \is_array($pair)
            && \array_is_list($pair)
            && \count($pair) === 2
            && \is_string($pair[0])
            && (\is_int($pair[1]) || (\is_string($pair[1]) && $pair[1] !== ''));
    }

    /**
     * Whether `$member` is a `[type, id]` pair, or a `[type, id, row]` triple carrying
     * the link row the pair was read from.
     *
     * @phpstan-assert-if-true array{0: string, 1: string|int, 2?: mixed} $member
     */
    public static function isMember(mixed $member): bool
    {
        return \is_array($member)
            && \array_is_list($member)
            && (\count($member) === 2 || \count($member) === 3)
            && self::isPair([$member[0], $member[1]]);
    }

    /**
     * The stored id as the target's serializer would render it: through the id field's
     * encoder when the target type declares one. The stored id may be the storage key or
     * the wire id already. The encoder comes from the Resource `$type` was registered
     * with when `$serializer` is not that Resource itself, such as a decorator wrapping it.
     */
    public static function wireId(SerializerInterface $serializer, string $type, string|int $id, ?SerializerResolverInterface $resolver = null): string
    {
        foreach (RegisteredResource::of($serializer, $type, $resolver)?->allFields() ?? [] as $field) {
            if ($field instanceof Id) {
                return StoredIdForms::wireId($field->encoder(), $id);
            }
        }

        return StoredIdForms::wireId(null, $id);
    }

    /**
     * The class that declared the identifiedBy() closure, normally the owning
     * resource, for an error message.
     */
    public static function declaringClass(?\Closure $identifiedBy, mixed $model): string
    {
        $scope = $identifiedBy !== null ? (new \ReflectionFunction($identifiedBy))->getClosureScopeClass() : null;

        return $scope !== null ? $scope->getName() : 'a parent of type ' . \get_debug_type($model);
    }
}
