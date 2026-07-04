<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Constraint;

use haddowg\JsonApi\OpenApi\Schema;

/**
 * Array items must be unique (JSON Schema `uniqueItems: true`).
 */
final readonly class UniqueItems implements ProvidesJsonSchema
{
    public function __construct(
        public Context $context = new Context(),
    ) {}

    public function context(): Context
    {
        return $this->context;
    }

    public function contribute(Schema $schema): Schema
    {
        return $schema->withUniqueItems();
    }
}
