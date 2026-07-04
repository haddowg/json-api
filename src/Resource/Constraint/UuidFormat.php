<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Constraint;

use haddowg\JsonApi\OpenApi\Schema;

/**
 * String must be a valid UUID (JSON Schema `format: uuid`). An optional
 * `$version` (1–8) narrows to a specific RFC 4122 version; `null` allows any.
 */
final readonly class UuidFormat implements ProvidesJsonSchema
{
    public function __construct(
        public ?int $version = null,
        public Context $context = new Context(),
    ) {}

    public function context(): Context
    {
        return $this->context;
    }

    public function contribute(Schema $schema): Schema
    {
        return $schema->withFormat('uuid');
    }
}
