<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Constraint;

use haddowg\JsonApi\OpenApi\Schema;

/**
 * Minimum number of object properties (JSON Schema `minProperties`).
 */
final readonly class MinProperties implements ProvidesJsonSchema
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
        return $schema->withMinProperties($this->value);
    }
}
