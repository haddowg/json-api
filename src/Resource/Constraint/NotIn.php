<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Constraint;

use haddowg\JsonApi\OpenApi\Schema;

/**
 * Value must NOT be one of an enumerated set (JSON Schema `not: { enum }`).
 *
 * @template T
 */
final readonly class NotIn implements ProvidesJsonSchema
{
    /**
     * @var list<T>
     */
    public array $values;

    /**
     * @param list<T> $values
     */
    public function __construct(
        array $values,
        public Context $context = new Context(),
    ) {
        $this->values = $values;
    }

    public function context(): Context
    {
        return $this->context;
    }

    public function contribute(Schema $schema): Schema
    {
        return $schema->withNot(Schema::create()->withEnum($this->values));
    }
}
