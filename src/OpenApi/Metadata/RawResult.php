<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

use haddowg\JsonApi\OpenApi\Header;

/**
 * A custom action response whose body is not a JSON:API document: a CSV export, a PDF,
 * an image. It sits beside the action's JSON:API response at the same status, one per
 * media type, and the request's `Accept` header picks between them
 * ({@see \haddowg\JsonApi\Negotiation\AcceptNegotiator}). At runtime the action answers
 * with a {@see \haddowg\JsonApi\Response\RawResponse}.
 *
 * The body is described in one of three ways, or not at all:
 *
 *  - `schema` as an inline JSON Schema array;
 *  - `schema` as the class name of a {@see DescribesRawBody}, projected once as a named
 *    component;
 *  - `binary: true` for an opaque file, projected as `{type: string, format: binary}`.
 *
 * `headers` documents response headers such as `Content-Disposition`: a string value is
 * the header's description, or pass a {@see Header}. Declare everything with `new` and
 * constant arguments, so a `RawResult` can be written inside attribute arguments.
 */
final readonly class RawResult implements ActionResponse
{
    /**
     * @param array<string, mixed>|class-string<DescribesRawBody>|null $schema
     * @param array<string, string|Header>                              $headers header name → description or {@see Header}
     * @param mixed                                                     $example an example body; `null` means none
     */
    public function __construct(
        public string $mediaType,
        public array|string|null $schema = null,
        public bool $binary = false,
        public array $headers = [],
        public mixed $example = null,
        public int $status = 200,
    ) {
        $essence = \strtolower(\trim(\explode(';', $mediaType, 2)[0]));
        if (\preg_match('#^[a-z0-9!\#$&^_.+-]+/[a-z0-9!\#$&^_.+-]+$#', $essence) !== 1 || \str_contains($essence, '*')) {
            throw new \InvalidArgumentException(\sprintf('A raw action response needs a concrete media type such as "text/csv"; got "%s".', $mediaType));
        }
        if ($essence === 'application/vnd.api+json') {
            throw new \InvalidArgumentException('A raw action response cannot use the JSON:API media type; declare an ActionResource or MetaResult for the JSON:API body.');
        }
        if ($status < 200 || $status > 299 || $status === 204) {
            throw new \InvalidArgumentException(\sprintf('A raw action response needs a 2xx status with a body; got %d.', $status));
        }
        if ($binary && $schema !== null) {
            throw new \InvalidArgumentException('A raw action response is either binary or described by a schema, not both.');
        }
        if (\is_string($schema) && !\is_a($schema, DescribesRawBody::class, true)) {
            throw new \InvalidArgumentException(\sprintf('A raw action response schema class must implement %s; got "%s".', DescribesRawBody::class, $schema));
        }
    }

    public function status(): int
    {
        return $this->status;
    }

    public function jobType(): ?string
    {
        return null;
    }

    /**
     * The media type without parameters, lowercased: the key it is projected and
     * negotiated under.
     */
    public function essence(): string
    {
        return \strtolower(\trim(\explode(';', $this->mediaType, 2)[0]));
    }
}
