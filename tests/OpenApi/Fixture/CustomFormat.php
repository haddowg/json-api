<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi\Fixture;

use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\Resource\Constraint\Context;
use haddowg\JsonApi\Resource\Constraint\ProvidesJsonSchema;

/**
 * An application constraint contributing a format of its own choosing.
 */
final readonly class CustomFormat implements ProvidesJsonSchema
{
    public function __construct(
        public string $format,
        public Context $context = new Context(),
    ) {}

    public function context(): Context
    {
        return $this->context;
    }

    public function contribute(Schema $schema): Schema
    {
        return $schema->withFormat($this->format);
    }
}
