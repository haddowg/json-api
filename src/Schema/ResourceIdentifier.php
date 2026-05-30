<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema;

use haddowg\JsonApi\Exception\ResourceIdentifierIdInvalid;
use haddowg\JsonApi\Exception\ResourceIdentifierIdMissing;
use haddowg\JsonApi\Exception\ResourceIdentifierTypeInvalid;
use haddowg\JsonApi\Exception\ResourceIdentifierTypeMissing;

/**
 * A JSON:API resource identifier object: the `{type, id, meta}` triple that
 * references a resource without carrying its full representation.
 *
 * Construct-only and immutable. {@see fromArray()} validates a decoded document
 * fragment and throws the typed `ResourceIdentifier*` exceptions on the same
 * conditions yin's `ExceptionFactory` did — there is no exception-factory
 * indirection.
 *
 * @see https://github.com/woohoolabs/yin — original work (MIT), from which this derives.
 * @see https://jsonapi.org/format/1.1/#document-resource-identifier-objects
 */
final readonly class ResourceIdentifier
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public string $type,
        public string $id,
        public array $meta = [],
    ) {}

    /**
     * Build a resource identifier from a decoded document fragment, validating
     * the required `type` and `id` members.
     *
     * @param array<string, mixed> $array
     *
     * @throws ResourceIdentifierTypeMissing
     * @throws ResourceIdentifierTypeInvalid
     * @throws ResourceIdentifierIdMissing
     * @throws ResourceIdentifierIdInvalid
     */
    public static function fromArray(array $array): self
    {
        if (!isset($array['type']) || $array['type'] === '') {
            throw new ResourceIdentifierTypeMissing($array);
        }

        if (!\is_string($array['type'])) {
            throw new ResourceIdentifierTypeInvalid(\gettype($array['type']));
        }

        if (!isset($array['id']) || $array['id'] === '') {
            throw new ResourceIdentifierIdMissing($array);
        }

        if (!\is_string($array['id'])) {
            throw new ResourceIdentifierIdInvalid(\gettype($array['id']));
        }

        $meta = [];
        if (isset($array['meta']) && \is_array($array['meta'])) {
            /** @var array<string, mixed> $meta */
            $meta = $array['meta'];
        }

        return new self($array['type'], $array['id'], $meta);
    }

    /**
     * @internal Serializes the object to its JSON:API representation.
     *
     * @return array<string, mixed>
     */
    public function transform(): array
    {
        $result = [
            'type' => $this->type,
            'id' => $this->id,
        ];

        if ($this->meta !== []) {
            $result['meta'] = $this->meta;
        }

        return $result;
    }
}
