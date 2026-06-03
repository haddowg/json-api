<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Constraint;

/**
 * String must be a valid UUID (JSON Schema `format: uuid`). An optional
 * `$version` (1–8) narrows to a specific RFC 4122 version; `null` allows any.
 */
final readonly class UuidFormat implements \haddowg\JsonApi\Resource\Constraint\ConstraintInterface
{
    public function __construct(
        public ?int $version = null,
        public Context $context = new Context(),
    ) {}

    public function context(): Context
    {
        return $this->context;
    }
}
