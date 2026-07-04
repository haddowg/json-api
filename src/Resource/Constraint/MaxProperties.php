<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Constraint;

use haddowg\JsonApi\OpenApi\Schema;

/**
 * Maximum number of object properties (JSON Schema `maxProperties`).
 */
final readonly class MaxProperties implements ProvidesJsonSchema
{
    public function __construct(
        public int $value,
        public Context $context = new Context(),
    ) {}

    public function context(): Context
    {
        return $this->context;
    }

    public function contribute(Schema $schema): Schema
    {
        return $schema->withMaxProperties($this->value);
    }
}
