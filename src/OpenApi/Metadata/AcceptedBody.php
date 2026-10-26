<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * One request body media type a custom action accepts, declared through
 * {@see DeclaresAcceptedBodies}. The projector writes one `requestBody.content` entry
 * per accepted body, keyed by its media type, which may be a wildcard such as `image/*`.
 *
 * The entry's schema is, in order:
 *  - the action's JSON:API request document when the body is a `document` (the default
 *    for `application/vnd.api+json`);
 *  - a binary string when `binary` is set;
 *  - the `$ref` of the {@see DescribesRawBody} class `schema` names, emitted once as a
 *    component named by its short class name, exactly as a {@see RawResult}'s is;
 *  - the inline `schema` array;
 *  - otherwise an open object for `multipart/form-data` and a binary string for any
 *    other type.
 */
final readonly class AcceptedBody
{
    public bool $document;

    /**
     * @param string                                              $mediaType a `type/subtype`, where either part may be `*`; parameters are ignored
     * @param array<string, mixed>|class-string<DescribesRawBody>|null $schema    the body's JSON Schema, inline or as a class that supplies it
     * @param bool                                                $binary    the body is opaque bytes, described as a binary string
     * @param bool|null                                           $document  the body is the action's JSON:API request document; defaults to whether the media type is JSON:API
     */
    public function __construct(
        public string $mediaType,
        public array|string|null $schema = null,
        public bool $binary = false,
        ?bool $document = null,
    ) {
        $essence = $this->essence();
        if ($essence !== '*/*' && \preg_match('#^[a-z0-9!\#$&^_.+-]+/([a-z0-9!\#$&^_.+-]+|\*)$#', $essence) !== 1) {
            throw new \InvalidArgumentException(\sprintf('An accepted request body needs a media type such as "text/csv" or "image/*"; got "%s".', $mediaType));
        }

        $this->document = $document ?? $essence === 'application/vnd.api+json';
        if (($this->document ? 1 : 0) + ($binary ? 1 : 0) + ($schema !== null ? 1 : 0) > 1) {
            throw new \InvalidArgumentException(\sprintf('The accepted request body "%s" is a JSON:API document, binary or described by a schema; pick one.', $mediaType));
        }

        if (\is_string($schema) && !\is_a($schema, DescribesRawBody::class, true)) {
            throw new \InvalidArgumentException(\sprintf('An accepted request body schema class must implement %s; got "%s".', DescribesRawBody::class, $schema));
        }
    }

    /**
     * The bodies `$action` declares through {@see DeclaresAcceptedBodies}, or none.
     *
     * @return list<self>
     */
    public static function of(ActionMetadataInterface $action): array
    {
        return $action instanceof DeclaresAcceptedBodies ? $action->accepts() : [];
    }

    /**
     * The media type without parameters, lower-cased.
     */
    public function essence(): string
    {
        return \strtolower(\trim(\explode(';', $this->mediaType, 2)[0]));
    }
}
