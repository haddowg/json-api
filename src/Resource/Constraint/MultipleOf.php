<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Constraint;

use haddowg\JsonApi\OpenApi\Schema;

/**
 * Value must be a multiple of this number (JSON Schema `multipleOf`).
 */
final readonly class MultipleOf implements ProvidesJsonSchema
{
    public function __construct(
        public int|float $value,
        public Context $context = new Context(),
    ) {}

    public function context(): Context
    {
        return $this->context;
    }

    public function contribute(Schema $schema): Schema
    {
        return $schema->withMultipleOf($this->value);
    }
}
