<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi\Fixture;

use haddowg\JsonApi\OpenApi\ParameterStyle;
use haddowg\JsonApi\OpenApi\QueryParameterShape;
use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\Resource\Constraint\ConstraintInterface;
use haddowg\JsonApi\Resource\Filter\DescribesQueryParameter;

/**
 * A consumer-defined filter whose value is a comma-separated list — a `form`/array
 * OpenAPI parameter, a shape none of core's built-in filters produce. Proves an
 * application filter outside core's vocabulary can describe its own parameter
 * envelope through {@see DescribesQueryParameter} and document correctly with no
 * change to the projector.
 */
final readonly class CommaListFilter implements DescribesQueryParameter
{
    public function __construct(private string $key) {}

    public function key(): string
    {
        return $this->key;
    }

    /**
     * @return list<ConstraintInterface>
     */
    public function constraints(): array
    {
        return [];
    }

    public function describeQueryParameter(Schema $valueSchema): QueryParameterShape
    {
        return new QueryParameterShape(
            Schema::ofType('array')->withItems($valueSchema),
            ParameterStyle::Form,
            false,
        );
    }
}
