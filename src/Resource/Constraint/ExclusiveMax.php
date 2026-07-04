<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Constraint;

use haddowg\JsonApi\OpenApi\Schema;

/**
 * Exclusive upper bound (JSON Schema `exclusiveMaximum`).
 */
final readonly class ExclusiveMax implements ProvidesJsonSchema
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
        return $schema->withExclusiveMaximum($this->value);
    }
}
