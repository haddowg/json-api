<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Constraint;

use haddowg\JsonApi\OpenApi\Schema;

/**
 * String must be a valid ULID — a 26-character Crockford base32 value
 * (case-insensitive). Used as the client-generated-id format constraint for
 * {@see \haddowg\JsonApi\Resource\Field\Id::ulid()}.
 */
final readonly class UlidFormat implements ProvidesJsonSchema
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
        return $schema->withPattern(\haddowg\JsonApi\Resource\Field\Id::ULID_FORMAT_PATTERN);
    }
}
