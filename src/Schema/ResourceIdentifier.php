<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema;

use haddowg\JsonApi\Exception\ResourceIdentifierIdInvalid;
use haddowg\JsonApi\Exception\ResourceIdentifierIdMissing;
use haddowg\JsonApi\Exception\ResourceIdentifierLidInvalid;
use haddowg\JsonApi\Exception\ResourceIdentifierTypeInvalid;
use haddowg\JsonApi\Exception\ResourceIdentifierTypeMissing;

/**
 * A JSON:API resource identifier object: the `{type, id|lid, meta}` shape that
 * references a resource without carrying its full representation.
 *
 * Per JSON:API 1.1 a resource identifier MUST carry a `type` and MUST identify
 * the resource by `id` — except when it references a resource that has not yet
 * been created, in which case it carries a local id (`lid`) instead. Exactly one
 * of `id`/`lid` is therefore expected; both are modelled as nullable and
 * {@see fromArray()} enforces that at least one is present.
 *
 * Construct-only and immutable. {@see fromArray()} validates a decoded document
 * fragment and throws the typed `ResourceIdentifier*` exceptions directly — there
 * is no exception-factory indirection.
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
        public ?string $id = null,
        public ?string $lid = null,
        public array $meta = [],
    ) {}

    /**
     * Build a resource identifier from a decoded document fragment, validating
     * the required `type` member and that the resource is identified by `id` or
     * `lid`.
     *
     * @param array<string, mixed> $array
     *
     * @throws ResourceIdentifierTypeMissing
     * @throws ResourceIdentifierTypeInvalid
     * @throws ResourceIdentifierIdMissing
     * @throws ResourceIdentifierIdInvalid
     * @throws ResourceIdentifierLidInvalid
     */
    public static function fromArray(array $array): self
    {
        if (!isset($array['type']) || $array['type'] === '') {
            throw new ResourceIdentifierTypeMissing($array);
        }

        if (!\is_string($array['type'])) {
            throw new ResourceIdentifierTypeInvalid(\gettype($array['type']));
        }

        $hasId = isset($array['id']) && $array['id'] !== '';
        $hasLid = isset($array['lid']) && $array['lid'] !== '';

        if (!$hasId && !$hasLid) {
            throw new ResourceIdentifierIdMissing($array);
        }

        $id = null;
        if ($hasId) {
            if (!\is_string($array['id'])) {
                throw new ResourceIdentifierIdInvalid(\gettype($array['id']));
            }
            $id = $array['id'];
        }

        $lid = null;
        if ($hasLid) {
            if (!\is_string($array['lid'])) {
                throw new ResourceIdentifierLidInvalid(\gettype($array['lid']));
            }
            $lid = $array['lid'];
        }

        $meta = [];
        if (isset($array['meta']) && \is_array($array['meta'])) {
            /** @var array<string, mixed> $meta */
            $meta = $array['meta'];
        }

        return new self($array['type'], $id, $lid, $meta);
    }

    /**
     * @internal Serializes the object to its JSON:API representation.
     *
     * @return array<string, mixed>
     */
    public function transform(): array
    {
        $result = ['type' => $this->type];

        if ($this->id !== null) {
            $result['id'] = $this->id;
        }

        if ($this->lid !== null) {
            $result['lid'] = $this->lid;
        }

        if ($this->meta !== []) {
            $result['meta'] = $this->meta;
        }

        return $result;
    }
}
