<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

use haddowg\JsonApi\OpenApi\Metadata\DeclaresResourceMeta;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Serializer\DeclaresResourceMetaInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;

/**
 * Reads and renders the declared `meta` of a type's resource objects, for the engine, the
 * adapters and the OpenAPI projection alike.
 */
final class ResourceMeta
{
    /**
     * The declarations read so far, by declarer and direction.
     *
     * @var \WeakMap<object, array<string, MetaShape>>|null
     */
    private static ?\WeakMap $read = null;

    /**
     * The members `$declarer`, a type's serializer, hydrator or metadata, declares for the
     * direction `$context` describes. Empty when it declares nothing.
     *
     * @throws MetaMisdeclared
     */
    public static function forType(object $declarer, MetaContext $context, string $type = ''): MetaShape
    {
        $direction = match (true) {
            $context->isRead() => 'read',
            $context->creating() => 'create',
            default => 'update',
        };
        $owner = \sprintf('the "%s" resource object %s', $type, $direction === 'read' ? 'read' : $direction . ' request');
        if (!($declarer instanceof DeclaresResourceMetaInterface || $declarer instanceof DeclaresResourceMeta)) {
            return MetaShape::none($owner);
        }

        return MetaShape::of($declarer->resourceMeta($context), $owner);
    }

    /**
     * The read declaration of `$serializer`, read once per instance.
     *
     * @throws MetaMisdeclared
     */
    public static function readShapeOf(object $serializer, string $type = ''): MetaShape
    {
        self::$read ??= new \WeakMap();
        $memo = self::$read[$serializer] ?? [];
        if (!isset($memo['read'])) {
            $memo['read'] = self::forType($serializer, MetaContext::read(), $type);
            self::$read[$serializer] = $memo;
        }

        return $memo['read'];
    }

    /**
     * A resource object's rendered `meta`: the declared members a hook supplies, then what
     * the serializer's `getMeta()` returns, which wins on a clash.
     *
     * @return array<string, mixed>
     */
    public static function render(SerializerInterface $serializer, mixed $object, JsonApiRequestInterface $request): array
    {
        $meta = $serializer->getMeta($object, $request);
        if (!$serializer instanceof DeclaresResourceMetaInterface) {
            return $meta;
        }

        $shape = self::readShapeOf($serializer, $serializer->getType($object));
        if (!$shape->extractsAny()) {
            return $meta;
        }

        return [...$shape->extract($object, $request), ...$meta];
    }
}
