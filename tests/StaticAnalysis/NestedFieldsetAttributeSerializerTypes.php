<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\StaticAnalysis;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\NestedFieldset;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\AbstractSerializer;
use haddowg\JsonApi\Serializer\SerializesNestedFieldsetsInterface;

/**
 * Never executed. phpstan-hooks.neon analyses it with PHPStan's default settings, as a
 * consuming project would, so a serializer override selecting keys type-checks the way an
 * author writes one.
 */
final class NestedFieldsetAttributeSerializerTypes extends AbstractSerializer implements SerializesNestedFieldsetsInterface
{
    public function getType(mixed $object): string
    {
        return 'places';
    }

    public function getId(mixed $object): string
    {
        return $object instanceof \ArrayObject ? (string) $object->count() : '';
    }

    public function getMeta(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }

    public function getLinks(mixed $object, JsonApiRequestInterface $request): ?ResourceLinks
    {
        return null;
    }

    public function getAttributes(mixed $object, JsonApiRequestInterface $request): array
    {
        return ['address' => static fn(mixed $model): mixed => $model instanceof \ArrayObject ? $model->getArrayCopy() : null];
    }

    public function getNestedFieldsetAttribute(mixed $object, JsonApiRequestInterface $request, string $name, NestedFieldset $fieldset): mixed
    {
        $selected = [];
        foreach ($fieldset->keys as $key) {
            $selected[$key] = $fieldset->nested($key)?->paths() ?? $fieldset->items()?->keys;
        }

        return $selected;
    }

    public function getDefaultIncludedRelationships(mixed $object): array
    {
        return [];
    }

    public function getRelationships(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }
}
