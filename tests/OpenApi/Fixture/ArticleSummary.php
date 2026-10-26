<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi\Fixture;

use haddowg\JsonApi\OpenApi\Metadata\DescribesRawBody;

/**
 * A plain-JSON report body described by a schema class, projected as a named component.
 */
final class ArticleSummary implements DescribesRawBody
{
    public static function jsonSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'total' => ['type' => 'integer', 'minimum' => 0],
                'byStatus' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
            ],
            'required' => ['total'],
        ];
    }
}
