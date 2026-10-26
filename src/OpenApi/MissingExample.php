<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

/**
 * A value the projector needed for an operation's example and found no declared example
 * for, so that operation got no generated example.
 *
 * `operation` is the operation's `operationId`, or for a shared error response the
 * `components.responses` entry's name. `type` is the JSON:API type whose field has no
 * example, or the error code. `field` is the dotted path to the member: `id`, `title`,
 * `address.street`, `tags.pivot.position`, or `source` for an error code that names a
 * source location but declares no example of one.
 */
final readonly class MissingExample implements \Stringable
{
    public function __construct(
        public string $operation,
        public string $type,
        public string $field,
    ) {}

    public function message(): string
    {
        return \sprintf('%s has no example: "%s" declares no example for "%s".', $this->operation, $this->type, $this->field);
    }

    public function __toString(): string
    {
        return $this->message();
    }
}
