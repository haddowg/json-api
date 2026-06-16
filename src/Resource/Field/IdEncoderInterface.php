<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * Transforms a resource id between its storage key and its wire form.
 *
 * The domain entity always holds the **storage key** (e.g. a binary UUID or an
 * integer primary key); the **wire id** a client sees in the JSON:API `id`
 * member and in URLs is `encode($storageKey)`. An encoder attached to the
 * {@see Id} field (via {@see Id::encodeUsing()}) drives the entity's own id
 * transform: {@see encode()} runs on serialize, and {@see decode()} runs on a
 * client-generated id at create so the new entity ends up holding the storage
 * key — exactly like a read entity.
 *
 * A type with no encoder behaves as if wire == storage; nothing is transformed.
 */
interface IdEncoderInterface
{
    /**
     * Encodes a storage key into its wire id (storage -> wire).
     */
    public function encode(mixed $storageKey): string;

    /**
     * Decodes a wire id into its storage key (wire -> storage).
     *
     * Return `null` when the wire id cannot be decoded; the caller treats an
     * undecodable id as an invalid target (422 on create, 404 on lookup).
     */
    public function decode(string $wireId): mixed;
}
