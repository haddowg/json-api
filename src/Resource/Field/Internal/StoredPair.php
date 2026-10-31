<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field\Internal;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
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
     * The stored key as the target's serializer would render its id: through the id
     * field's encoder when the target type declares one.
     */
    public static function wireId(SerializerInterface $serializer, string|int $id): string
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
    public static function declaringClass(?\Closure $identifiedBy, mixed $model): string
    {
        $scope = $identifiedBy !== null ? (new \ReflectionFunction($identifiedBy))->getClosureScopeClass() : null;

        return $scope !== null ? $scope->getName() : 'a parent of type ' . \get_debug_type($model);
    }
}
